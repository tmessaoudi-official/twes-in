<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldDefinitionRepository;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldValues;
use App\CustomFields\Domain\InvalidCustomFieldValue;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\TaxKind;
use App\Fiscal\Domain\Unit;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Products\Domain\Barcode;
use App\Module\Products\Domain\BarcodeLine;
use App\Module\Products\Domain\BarcodeRole;
use App\Module\Products\Domain\InvalidProduct;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductCategory;
use App\Module\Products\Domain\ProductCategoryRepository;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Domain\ProductSearch;
use App\Module\Products\Domain\ProductTracking;
use App\Module\Vendors\Domain\VendorRepository;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's products. A product is sold in one of the company's active units and filed in one of its categories;
 * its default taxes are the company's active taxes charged on a line (VAT and levies: a stamp or a withholding
 * belongs to the document). A unit or a tax retired since stays with the products that already have it. Custom
 * field values are checked against the company's fields for products. Audited with the names of the fields a
 * revision changed, never their values.
 */
final readonly class ManageProducts
{
    public const string ENTITY_TYPE = 'product';
    public const string CREATED = 'product.created';
    public const string REVISED = 'product.revised';

    public function __construct(
        private ProductRepository $products,
        private ProductCategoryRepository $categories,
        private UnitRepository $units,
        private TaxComponentRepository $taxes,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private CustomFieldDefinitionRepository $customFields,
        private ProductStockHistory $stockHistory,
        private Transactions $transactions,
        private VendorRepository $vendors,
    ) {
    }

    /** @return Page<Product> */
    public function search(Company $company, ProductSearch $search, PageRequest $page): Page
    {
        return $this->products->search($company->getId(), $search, $page);
    }

    /** @return list<Product> */
    public function list(Company $company): array
    {
        return $this->products->ofCompany($company->getId());
    }

    /** @throws ProductNotFound */
    public function get(Company $company, Uuid $id): Product
    {
        return $this->products->ofIdInCompany($id, $company->getId()) ?? throw new ProductNotFound();
    }

    /**
     * @throws ProductReferenceTaken
     * @throws ProductBarcodeTaken
     * @throws InvalidProduct
     */
    public function create(Company $company, ProductInput $input, ?Uuid $actorUserId): Product
    {
        return $this->transactions->run(function () use ($company, $input, $actorUserId): Product {
            if (null !== $this->products->ofReferenceInCompany(trim($input->reference), $company->getId())) {
                throw new ProductReferenceTaken();
            }
            $details = $input->seesCosts ? $input->details : $input->details->withCostPrice(null);
            $rows = $input->barcodes ?? [];
            $lines = $this->barcodeLines($company, $input->seesCosts ? $rows : $this->unseenCodesKept($rows, null), null);
            [$unit, $category] = $this->checked($company, $input, null);
            $tracking = Product::trackingFor($input->tracking ?? ProductTracking::None, $input->details->kind);
            $values = $this->customFieldValues($company, $input, null);
            $now = $this->clock->now();
            $product = Product::create($company, $input->reference, $details, $unit, $category, $input->defaultTaxComponentIds, $now);
            $product->track($tracking, $now);
            $product->reviseCustomFields($values, $now);
            $product->replaceBarcodes($lines, $now);
            if (!$input->isActive) {
                $product->revise($input->reference, $details, $unit, $category, $input->defaultTaxComponentIds, false, $now);
            }
            $this->products->save($product);
            $this->record($company, $product->getId(), self::CREATED, [], $actorUserId);

            return $product;
        });
    }

    /**
     * @throws ProductNotFound
     * @throws ProductReferenceTaken
     * @throws ProductBarcodeTaken
     * @throws InvalidProduct
     */
    public function revise(Company $company, Uuid $id, ProductInput $input, ?Uuid $actorUserId): Product
    {
        return $this->transactions->run(function () use ($company, $id, $input, $actorUserId): Product {
            $product = $this->get($company, $id);
            $holder = $this->products->ofReferenceInCompany(trim($input->reference), $company->getId());
            if (null !== $holder && !$holder->getId()->equals($product->getId())) {
                throw new ProductReferenceTaken();
            }
            $details = $input->seesCosts ? $input->details : $input->details->withCostPrice($product->getDetails()->costPrice);
            $rows = null === $input->barcodes || $input->seesCosts ? $input->barcodes : $this->unseenCodesKept($input->barcodes, $product);
            $lines = null === $rows ? null : $this->barcodeLines($company, $rows, $product);
            [$unit, $category] = $this->checked($company, $input, $product);
            $this->assertStockKeepsItsMeaning($company, $product, $unit, $input->details->kind);
            $tracking = $this->trackingKeptByStock($company, $product, Product::trackingFor($input->tracking ?? $product->getTracking(), $input->details->kind));
            $values = $this->customFieldValues($company, $input, $product);

            $now = $this->clock->now();
            $changed = $product->revise($input->reference, $details, $unit, $category, $input->defaultTaxComponentIds, $input->isActive, $now);
            if ($product->track($tracking, $now)) {
                $changed[] = 'tracking';
            }
            $changed = [...$changed, ...$product->reviseCustomFields($values, $now)];
            if (null !== $lines && $product->replaceBarcodes($lines, $now)) {
                $changed[] = 'barcodes';
            }
            if ([] !== $changed) {
                $this->products->save($product);
                $this->record($company, $product->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $product;
        });
    }

    /**
     * The codes a product answers to become exactly these (docs/SPEC.md § 7, 2026-09-22 11:05), written on their own
     * like the product's other tabs, and audited as a revision of the product's `barcodes`.
     *
     * @param list<BarcodeInput> $rows
     *
     * @throws ProductNotFound
     * @throws ProductBarcodeTaken
     * @throws InvalidProduct
     */
    public function replaceBarcodes(Company $company, Uuid $id, array $rows, ?Uuid $actorUserId, bool $seesCosts = true): Product
    {
        return $this->transactions->run(function () use ($company, $id, $rows, $actorUserId, $seesCosts): Product {
            $product = $this->get($company, $id);
            $rows = $seesCosts ? $rows : $this->unseenCodesKept($rows, $product);
            if ($product->replaceBarcodes($this->barcodeLines($company, $rows, $product), $this->clock->now())) {
                $this->products->save($product);
                $this->record($company, $product->getId(), self::REVISED, ['fields' => ['barcodes']], $actorUserId);
            }

            return $product;
        });
    }

    /**
     * The codes a writer who may not read costs sends, with the supplier's codes they were never shown kept as stored
     * (docs/SPEC.md § 7, 2026-09-23 09:45, slice 5): a list they cannot see whole would otherwise erase them.
     *
     * @param list<BarcodeInput> $rows
     *
     * @return list<BarcodeInput>
     *
     * @throws InvalidProduct when a row is a supplier's code, which only one who may read costs writes
     */
    private function unseenCodesKept(array $rows, ?Product $held): array
    {
        foreach ($rows as $index => $row) {
            if (BarcodeRole::Supplier->value === $row->role) {
                throw new InvalidProduct("barcodes.$index.role", 'A supplier\'s code is written by someone who may read costs.');
            }
        }
        foreach ($held?->getBarcodes() ?? [] as $code) {
            if (BarcodeRole::Supplier === $code->getRole()) {
                $rows[] = new BarcodeInput($code->getRole()->value, $code->getCode(), $code->getQuantity(), $code->getSupplier()?->getId());
            }
        }

        return $rows;
    }

    /**
     * What the input names, found in the company and checked; what the product already has is kept even retired.
     *
     * @return array{Unit, ProductCategory|null}
     */
    private function checked(Company $company, ProductInput $input, ?Product $current): array
    {
        $unit = $this->units->ofIdInCompany($input->unitId, $company->getId())
            ?? throw new InvalidProduct('unitId', 'No unit of this company has this id.');
        if (!$unit->isActive() && !(null !== $current && $current->getUnit()->getId()->equals($unit->getId()))) {
            throw new InvalidProduct('unitId', \sprintf('The unit %s is retired.', $unit->getCode()));
        }

        $category = null;
        if (null !== $input->categoryId) {
            $category = $this->categories->ofIdInCompany($input->categoryId, $company->getId())
                ?? throw new InvalidProduct('categoryId', 'No product category of this company has this id.');
        }

        $kept = $current?->getDefaultTaxComponentIds() ?? [];
        foreach ($input->defaultTaxComponentIds as $taxId) {
            $tax = $this->taxes->ofIdInCompany($taxId, $company->getId())
                ?? throw new InvalidProduct('defaultTaxComponentIds', \sprintf('No tax of this company has the id %s.', $taxId->toRfc4122()));
            if (!$tax->isActive() && !\in_array($taxId->toRfc4122(), $kept, true)) {
                throw new InvalidProduct('defaultTaxComponentIds', \sprintf('The tax %s is retired.', $tax->getCode()));
            }
            if (TaxKind::PercentageLine !== $tax->getKind()) {
                throw new InvalidProduct('defaultTaxComponentIds', \sprintf('%s is charged on a document, not on a line.', $tax->getCode()));
            }
        }

        return [$unit, $category];
    }

    /**
     * Stock is the sum of a product's movements in its unit: once stock was moved, a new unit would silently rename every
     * figure (10 kg read as 10 g), and a service is never counted again. Becoming goods breaks nothing.
     *
     * @throws InvalidProduct
     */
    private function assertStockKeepsItsMeaning(Company $company, Product $product, Unit $unit, ProductKind $kind): void
    {
        $unitChanges = !$unit->getId()->equals($product->getUnit()->getId());
        $leavesGoods = ProductKind::Goods === $product->getDetails()->kind && ProductKind::Goods !== $kind;
        if ((!$unitChanges && !$leavesGoods) || !$this->stockHistory->hasMovements($product->getId(), $company->getId())) {
            return;
        }
        if ($unitChanges) {
            throw new InvalidProduct('unitId', \sprintf('Stock of %s was moved in %s, so it keeps that unit.', $product->getReference(), $product->getUnit()->getCode()));
        }

        throw new InvalidProduct('kind', \sprintf('Stock of %s was moved, so it stays goods.', $product->getReference()));
    }

    /**
     * A movement written without a lot cannot be given one afterwards, nor a lot taken off one (docs/SPEC.md § 7,
     * 2026-09-23 02:40): once stock moved, the tracking is the one it moved under.
     *
     * @throws InvalidProduct
     */
    private function trackingKeptByStock(Company $company, Product $product, ProductTracking $tracking): ProductTracking
    {
        if ($tracking !== $product->getTracking() && $this->stockHistory->hasMovements($product->getId(), $company->getId())) {
            throw new InvalidProduct('tracking', \sprintf('Stock of %s was moved %s, so it stays that way.', $product->getReference(), ProductTracking::None === $product->getTracking() ? 'without lots' : 'by '.$product->getTracking()->value));
        }

        return $tracking;
    }

    /**
     * The codes the input names, each checked as a line and against the company (docs/SPEC.md § 7, 2026-09-22 11:05):
     * a code another product holds, in any role, is refused naming that product. The product's own codes are its to
     * keep. A refusal names the row, `barcodes.<index>.<field>`, so a form can put it under the right line.
     *
     * @param list<BarcodeInput> $rows
     *
     * @return list<BarcodeLine>
     *
     * @throws InvalidProduct
     * @throws ProductBarcodeTaken
     */
    private function barcodeLines(Company $company, array $rows, ?Product $revising): array
    {
        $lines = [];
        foreach ($rows as $index => $row) {
            $role = BarcodeRole::tryFrom($row->role)
                ?? throw new InvalidProduct("barcodes.$index.role", 'A code is a unit, pack, supplier or internal code.');
            $supplier = null;
            if (null !== $row->supplierId) {
                $supplier = $this->vendors->ofIdInCompany($row->supplierId, $company->getId())
                    ?? throw new InvalidProduct("barcodes.$index.supplierId", 'No supplier of this company has this id.');
            }
            try {
                $line = new BarcodeLine($role, new Barcode($row->code), $row->quantity, $supplier);
            } catch (InvalidProduct $refused) {
                throw new InvalidProduct("barcodes.$index.{$refused->field}", $refused->getMessage());
            }
            $held = $this->products->barcodeOfKeyInCompany($line->barcode->key, $company->getId());
            if (null !== $held && !(null !== $revising && $held->getProduct()->getId()->equals($revising->getId()))) {
                throw new ProductBarcodeTaken($held->getProduct()->getReference(), $line->barcode->code, $index);
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /** @return array<string, string|int|float|bool> */
    private function customFieldValues(Company $company, ProductInput $input, ?Product $current): array
    {
        $rules = array_map(static fn (CustomFieldDefinition $field) => $field->rule(), $this->customFields->ofCompanyAndEntity($company->getId(), CustomFieldEntity::Product));
        try {
            return CustomFieldValues::checked($rules, $input->customFields, $current?->getCustomFields() ?? []);
        } catch (InvalidCustomFieldValue $refused) {
            throw new InvalidProduct($refused->field, $refused->getMessage());
        }
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $productId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $productId, $action, $actorUserId, $changes, $company->getId()));
    }
}

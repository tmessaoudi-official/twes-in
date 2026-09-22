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
use App\Module\Products\Domain\InvalidProduct;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductCategory;
use App\Module\Products\Domain\ProductCategoryRepository;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Domain\ProductSearch;
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
            $this->assertBarcodeIsFree($company, $input, null);
            [$unit, $category] = $this->checked($company, $input, null);
            $values = $this->customFieldValues($company, $input, null);
            $now = $this->clock->now();
            $product = Product::create($company, $input->reference, $input->details, $unit, $category, $input->defaultTaxComponentIds, $now);
            $product->reviseCustomFields($values, $now);
            if (!$input->isActive) {
                $product->revise($input->reference, $input->details, $unit, $category, $input->defaultTaxComponentIds, false, $now);
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
            $this->assertBarcodeIsFree($company, $input, $product);
            [$unit, $category] = $this->checked($company, $input, $product);
            $this->assertStockKeepsItsMeaning($company, $product, $unit, $input->details->kind);
            $values = $this->customFieldValues($company, $input, $product);

            $now = $this->clock->now();
            $changed = $product->revise($input->reference, $input->details, $unit, $category, $input->defaultTaxComponentIds, $input->isActive, $now);
            $changed = [...$changed, ...$product->reviseCustomFields($values, $now)];
            if ([] !== $changed) {
                $this->products->save($product);
                $this->record($company, $product->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $product;
        });
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
     * docs/SPEC.md § 7, 2026-09-17: a barcode is unique within the company WHEN SET. A product without one is
     * not holding a value, so any number of them coexist — which matters, since many products have no barcode.
     *
     * @throws ProductBarcodeTaken
     */
    private function assertBarcodeIsFree(Company $company, ProductInput $input, ?Product $revising): void
    {
        $barcode = $input->details->barcode;
        if (null === $barcode) {
            return;
        }
        $holder = $this->products->ofBarcodeInCompany($barcode, $company->getId());
        if (null === $holder || (null !== $revising && $holder->getId()->equals($revising->getId()))) {
            return;
        }

        throw new ProductBarcodeTaken($holder->getReference());
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

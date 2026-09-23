<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Application;

use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldType;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\Unit;
use App\Module\Products\Application\BarcodeInput;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Application\ProductBarcodeTaken;
use App\Module\Products\Application\ProductInput;
use App\Module\Products\Application\ProductNotFound;
use App\Module\Products\Application\ProductReferenceTaken;
use App\Module\Products\Domain\InvalidProduct;
use App\Module\Products\Domain\ProductCategory;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCustomFieldDefinitions;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryProductCategories;
use App\Tests\Support\InMemoryProducts;
use App\Tests\Support\InMemoryProductStockHistory;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\InMemoryVendors;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ManageProductsTest extends TestCase
{
    private InMemoryUnits $units;
    private InMemoryTaxComponents $taxes;
    private InMemoryProductCategories $categories;
    private InMemoryCustomFieldDefinitions $fields;
    private InMemoryAuditTrail $audit;
    private InMemoryProductStockHistory $stockHistory;
    private InMemoryVendors $vendors;
    private ManageProducts $manage;
    private Company $company;
    private Company $globex;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-09-14 09:00:00');
        $this->units = new InMemoryUnits();
        $this->taxes = new InMemoryTaxComponents();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, $this->units, new InMemoryEstablishments(), new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $clock);
        $this->categories = new InMemoryProductCategories();
        $this->fields = new InMemoryCustomFieldDefinitions();
        $transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($transactions);
        $this->stockHistory = new InMemoryProductStockHistory();
        $this->vendors = new InMemoryVendors();
        $this->manage = new ManageProducts(new InMemoryProducts(), $this->categories, $this->units, $this->taxes, $this->audit, $clock, $this->fields, $this->stockHistory, $transactions, $this->vendors);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $provision->handle($this->company);
        $provision->handle($this->globex);
    }

    public function testAProductIsCreatedWithItsUnitCategoryAndLineTaxesAndAuditedWithoutValues(): void
    {
        $category = ProductCategory::create($this->company, 'Matériel', null, new \DateTimeImmutable());
        $this->categories->save($category);
        $actor = Uuid::v7();

        $product = $this->manage->create($this->company, $this->input(categoryId: $category->getId(), taxes: ['FODEC', 'TVA19']), $actor);

        self::assertSame([$product], $this->manage->list($this->company));
        self::assertSame($product, $this->manage->get($this->company, $product->getId()));
        self::assertSame('C62', $product->getUnit()->getCode());
        self::assertSame($category, $product->getCategory());
        self::assertSame([$this->tax('FODEC')->getId()->toRfc4122(), $this->tax('TVA19')->getId()->toRfc4122()], $product->getDefaultTaxComponentIds());
        $entry = $this->audit->entries[0];
        self::assertSame([ManageProducts::ENTITY_TYPE, ManageProducts::CREATED, [], $actor], [$entry->entityType, $entry->action, $entry->changes, $entry->actorUserId]);
        self::assertTrue($this->company->getId()->equals($entry->companyId));
    }

    public function testAProductCreatedInactiveIsInactive(): void
    {
        self::assertFalse($this->manage->create($this->company, $this->input(active: false), null)->isActive());
    }

    public function testAReferenceAnotherProductOfTheCompanyHasIsRefused(): void
    {
        $this->manage->create($this->globex, $this->input(company: $this->globex), null);
        $this->manage->create($this->company, $this->input(), null);
        $other = $this->manage->create($this->company, $this->input(reference: 'ART-002'), null);

        $this->manage->revise($this->company, $other->getId(), $this->input(reference: 'ART-002', name: 'Souris'), null);
        try {
            $this->manage->revise($this->company, $other->getId(), $this->input(reference: ' ART-001'), null);
            self::fail('A product took the reference another product has.');
        } catch (ProductReferenceTaken) {
        }
        $this->expectException(ProductReferenceTaken::class);
        $this->manage->create($this->company, $this->input(), null);
    }

    public function testWhatTheCompanyDoesNotHaveIsRefusedNamingTheField(): void
    {
        $theirCategory = ProductCategory::create($this->globex, 'Matériel', null, new \DateTimeImmutable());
        $this->categories->save($theirCategory);

        foreach ([
            'unitId' => $this->input(unitId: $this->unit('C62', $this->globex)->getId()),
            'categoryId' => $this->input(categoryId: $theirCategory->getId()),
            'defaultTaxComponentIds' => $this->input(taxIds: [$this->tax('TVA19', $this->globex)->getId()]),
        ] as $field => $input) {
            try {
                $this->manage->create($this->company, $input, null);
                self::fail('refused: '.$field);
            } catch (InvalidProduct $refused) {
                self::assertSame($field, $refused->field);
            }
        }
        self::assertSame([], $this->manage->list($this->company));
    }

    public function testOnlyTaxesChargedOnALineAreAProductsDefaults(): void
    {
        foreach (['TIMBRE', 'RS1'] as $code) {
            try {
                $this->manage->create($this->company, $this->input(taxes: [$code]), null);
                self::fail($code.' is charged on a document, not on a line');
            } catch (InvalidProduct $refused) {
                self::assertSame('defaultTaxComponentIds', $refused->field);
                self::assertStringContainsString($code, $refused->getMessage());
            }
        }
    }

    public function testARetiredUnitOrTaxIsRefusedToANewProductButStaysWithTheOnesThatHaveIt(): void
    {
        $product = $this->manage->create($this->company, $this->input(unit: 'HUR', taxes: ['TVA7']), null);
        $hour = $this->unit('HUR');
        $hour->revise($hour->getName(), $hour->getDecimals(), false, $hour->getSortOrder(), new \DateTimeImmutable());
        $vat7 = $this->tax('TVA7');
        $vat7->revise($vat7->getName(), $vat7->getRate(), $vat7->getAmount(), $vat7->getThreshold(), $vat7->entersVatBase(), $vat7->isDefault(), false, $vat7->getExemptionMention(), $vat7->getSortOrder(), 3, new \DateTimeImmutable());

        $this->manage->revise($this->company, $product->getId(), $this->input(unit: 'HUR', taxes: ['TVA7'], name: 'Installation'), null);
        self::assertSame(['fields' => ['name']], $this->audit->entries[1]->changes);

        foreach (['unitId' => $this->input(reference: 'ART-002', unit: 'HUR'), 'defaultTaxComponentIds' => $this->input(reference: 'ART-002', taxes: ['TVA7'])] as $field => $input) {
            try {
                $this->manage->create($this->company, $input, null);
                self::fail('refused: '.$field);
            } catch (InvalidProduct $refused) {
                self::assertSame($field, $refused->field);
            }
        }
        $this->manage->revise($this->company, $product->getId(), $this->input(unit: 'HUR', taxes: ['TVA7', 'FODEC'], name: 'Installation'), null);
        self::assertSame(['fields' => ['defaultTaxComponentIds']], $this->audit->entries[2]->changes, 'a kept retired tax travels with an added active one');
        $vat13 = $this->tax('TVA13');
        $vat13->revise($vat13->getName(), $vat13->getRate(), $vat13->getAmount(), $vat13->getThreshold(), $vat13->entersVatBase(), $vat13->isDefault(), false, $vat13->getExemptionMention(), $vat13->getSortOrder(), 3, new \DateTimeImmutable());
        $this->expectExceptionObject(new InvalidProduct('defaultTaxComponentIds', 'The tax TVA13 is retired.'));
        $this->manage->revise($this->company, $product->getId(), $this->input(unit: 'HUR', taxes: ['TVA7', 'TVA13']), null);
    }

    public function testARevisionIsAuditedWithTheFieldsItChangedAndNotAtAllWhenNothingChanged(): void
    {
        $product = $this->manage->create($this->company, $this->input(), null);

        $this->manage->revise($this->company, $product->getId(), $this->input(), null);
        self::assertCount(1, $this->audit->entries);

        $this->manage->revise($this->company, $product->getId(), $this->input(price: '1300', active: false), null);
        self::assertSame([ManageProducts::REVISED, ['fields' => ['unitPriceNet', 'isActive']]], [$this->audit->entries[1]->action, $this->audit->entries[1]->changes]);
        self::assertFalse($product->isActive());
    }

    public function testAProductWithStockHistoryKeepsItsUnitAndStaysGoods(): void
    {
        $product = $this->manage->create($this->company, $this->input(unit: 'KGM'), null);
        $this->stockHistory->withMovements[] = $product->getId()->toRfc4122();

        try {
            $this->manage->revise($this->company, $product->getId(), $this->input(unit: 'C62'), null);
            self::fail('The unit of a product with stock movements was changed.');
        } catch (InvalidProduct $refused) {
            self::assertSame('unitId', $refused->field);
        }
        try {
            $this->manage->revise($this->company, $product->getId(), $this->input(unit: 'KGM', kind: ProductKind::Service), null);
            self::fail('A product with stock movements became a service.');
        } catch (InvalidProduct $refused) {
            self::assertSame('kind', $refused->field);
        }
        self::assertSame(['KGM', ProductKind::Goods], [$product->getUnit()->getCode(), $product->getDetails()->kind]);

        $this->manage->revise($this->company, $product->getId(), $this->input(unit: 'KGM', price: '9'), null);
        self::assertSame('9.0000', $product->getDetails()->unitPriceNet, 'everything else is still revised');

        $service = $this->manage->create($this->company, $this->input(reference: 'SRV-001', unit: 'HUR', kind: ProductKind::Service), null);
        $this->stockHistory->withMovements[] = $service->getId()->toRfc4122();
        $this->manage->revise($this->company, $service->getId(), $this->input(reference: 'SRV-001', unit: 'HUR', kind: ProductKind::Goods), null);
        self::assertSame(ProductKind::Goods, $service->getDetails()->kind, 'becoming goods never breaks a stock figure');

        $fresh = $this->manage->create($this->company, $this->input(reference: 'ART-002', unit: 'KGM'), null);
        $this->manage->revise($this->company, $fresh->getId(), $this->input(reference: 'ART-002', unit: 'C62', kind: ProductKind::Service), null);
        self::assertSame(['C62', ProductKind::Service], [$fresh->getUnit()->getCode(), $fresh->getDetails()->kind], 'without movements both change');
    }

    /**
     * A product says whether its stock is kept by lot or by serial (docs/SPEC.md § 7, 2026-09-22 11:10, 2026-09-23 02:40),
     * and says it before its first movement: a movement written without a lot cannot be given one afterwards.
     */
    public function testTrackingIsSetBeforeTheFirstMovementAndAServiceTracksNothing(): void
    {
        $product = $this->manage->create($this->company, $this->input(), null);
        self::assertSame(ProductTracking::None, $product->getTracking(), 'a product is untracked until someone says otherwise');

        $this->manage->revise($this->company, $product->getId(), $this->input(tracking: ProductTracking::Lot), null);
        self::assertSame(ProductTracking::Lot, $product->getTracking());
        self::assertSame(['fields' => ['tracking']], $this->audit->entries[1]->changes);
        $this->manage->revise($this->company, $product->getId(), $this->input(price: '9'), null);
        self::assertSame(ProductTracking::Lot, $product->getTracking(), 'a revision that does not say keeps it');

        $this->stockHistory->withMovements[] = $product->getId()->toRfc4122();
        try {
            $this->manage->revise($this->company, $product->getId(), $this->input(tracking: ProductTracking::Serial), null);
            self::fail('The tracking of a product with stock movements was changed.');
        } catch (InvalidProduct $refused) {
            self::assertSame('tracking', $refused->field);
        }
        self::assertSame(ProductTracking::Lot, $product->getTracking());
        $this->manage->revise($this->company, $product->getId(), $this->input(tracking: ProductTracking::Lot, price: '8'), null);
        self::assertSame('8.0000', $product->getDetails()->unitPriceNet, 'saying the same tracking again is no change');

        foreach ([
            fn () => $this->manage->create($this->company, $this->input(reference: 'SRV-1', unit: 'HUR', kind: ProductKind::Service, tracking: ProductTracking::Serial), null),
            fn () => $this->manage->revise($this->company, $product->getId(), $this->input(kind: ProductKind::Service), null),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('A service was given a tracking.');
            } catch (InvalidProduct $refused) {
                self::assertContains($refused->field, ['tracking', 'kind']);
            }
        }
        $fresh = $this->manage->create($this->company, $this->input(reference: 'ART-2', tracking: ProductTracking::Lot), null);
        try {
            $this->manage->revise($this->company, $fresh->getId(), $this->input(reference: 'ART-2', kind: ProductKind::Service), null);
            self::fail('A tracked product became a service.');
        } catch (InvalidProduct $refused) {
            self::assertSame('tracking', $refused->field);
        }
    }

    public function testCustomFieldValuesAreCheckedAgainstTheCompanysFieldsForProductsOnly(): void
    {
        $now = new \DateTimeImmutable();
        $this->fields->save(CustomFieldDefinition::create($this->company, CustomFieldEntity::Product, 'warranty', 'Garantie (mois)', CustomFieldType::Number, true, [], 0, $now));
        $this->fields->save(CustomFieldDefinition::create($this->company, CustomFieldEntity::Customer, 'sector', 'Secteur', CustomFieldType::Text, true, [], 0, $now));

        try {
            $this->manage->create($this->company, $this->input(), null);
            self::fail("A product was created without the company's required field.");
        } catch (InvalidProduct $refused) {
            self::assertSame('customFields.warranty', $refused->field);
        }
        try {
            $this->manage->create($this->company, $this->input(customFields: ['warranty' => 24, 'sector' => 'retail']), null);
            self::fail("A customer's field was accepted on a product.");
        } catch (InvalidProduct $refused) {
            self::assertSame('customFields.sector', $refused->field);
        }

        $product = $this->manage->create($this->company, $this->input(customFields: ['warranty' => 24]), null);
        self::assertSame(['warranty' => 24], $product->getCustomFields());
        $this->manage->revise($this->company, $product->getId(), $this->input(customFields: ['warranty' => 36]), null);
        self::assertSame(['fields' => ['customFields.warranty']], $this->audit->entries[1]->changes);
    }

    public function testAnotherCompanysProductIsNotFound(): void
    {
        $theirs = $this->manage->create($this->globex, $this->input(company: $this->globex), null);

        try {
            $this->manage->get($this->company, $theirs->getId());
            self::fail("Another company's product was read.");
        } catch (ProductNotFound) {
        }
        $this->expectException(ProductNotFound::class);
        $this->manage->revise($this->company, $theirs->getId(), $this->input(), null);
    }

    /**
     * A code is unique across the company, every role together (docs/SPEC.md § 7, 2026-09-22 11:05): a pack of one
     * product may not carry the unit code of another, or a scan would find both.
     */
    public function testACodeAnotherProductHoldsInAnyRoleIsRefusedNamingThatProductAndTheRow(): void
    {
        $this->manage->create($this->globex, $this->input(company: $this->globex, barcodes: [new BarcodeInput('unit', '036000291452', 1)]), null);
        $this->manage->create($this->company, $this->input(barcodes: [new BarcodeInput('unit', '036000291452', 1)]), null);

        try {
            // The same UPC spelled as an EAN-13, on a pack: still the same code.
            $this->manage->create($this->company, $this->input(reference: 'ART-002', barcodes: [new BarcodeInput('internal', 'X-1', 1), new BarcodeInput('pack', '0036000291452', 6)]), null);
            self::fail('a second product took a code another holds');
        } catch (ProductBarcodeTaken $taken) {
            self::assertSame(['ART-001', '0036000291452', 1], [$taken->heldBy, $taken->barcode, $taken->index]);
        }
    }

    public function testARevisionKeepingItsOwnCodesGoesThroughAndIsAuditedAsBarcodes(): void
    {
        $product = $this->manage->create($this->company, $this->input(barcodes: [new BarcodeInput('unit', '036000291452', 1)]), null);

        $this->manage->revise($this->company, $product->getId(), $this->input(barcodes: [new BarcodeInput('unit', '036000291452', 1), new BarcodeInput('pack', '10012345678902', 12)]), null);

        self::assertCount(2, $product->getBarcodes());
        self::assertSame(['fields' => ['barcodes']], $this->audit->entries[1]->changes);
        // The same list again changes nothing and records nothing.
        $this->manage->revise($this->company, $product->getId(), $this->input(barcodes: [new BarcodeInput('pack', '10012345678902', 12), new BarcodeInput('unit', '036000291452', 1)]), null);
        self::assertCount(2, $this->audit->entries);
    }

    public function testASupplierCodeNamesASupplierOfTheCompanyAndARowIsRefusedByItsIndex(): void
    {
        $sotupa = Vendor::create($this->company, 'F-001', new VendorProfile('Sotupa'), new \DateTimeImmutable());
        $this->vendors->save($sotupa);

        $product = $this->manage->create($this->company, $this->input(barcodes: [new BarcodeInput('supplier', 'SOT-4471', 6, $sotupa->getId())]), null);
        self::assertSame($sotupa, $product->getBarcodes()[0]->getSupplier());

        foreach ([
            'barcodes.1.supplierId' => [new BarcodeInput('unit', 'A-1', 1), new BarcodeInput('supplier', 'SOT-9', 6, Uuid::v7())],
            'barcodes.0.code' => [new BarcodeInput('unit', '036000291453', 1)],
            'barcodes.0.quantity' => [new BarcodeInput('pack', 'P-1', 1)],
            'barcodes.0.role' => [new BarcodeInput('pallet', 'P-1', 1)],
        ] as $field => $barcodes) {
            try {
                $this->manage->create($this->company, $this->input(reference: 'ART-009', barcodes: $barcodes), null);
                self::fail('accepted: '.$field);
            } catch (InvalidProduct $refused) {
                self::assertSame($field, $refused->field);
            }
        }
    }

    /**
     * @param list<string>|null                    $taxes        codes of the company's taxes
     * @param list<Uuid>|null                      $taxIds       ids, when they are not the company's
     * @param array<string, string|int|float|bool> $customFields
     * @param list<BarcodeInput>                   $barcodes
     */
    private function input(
        string $reference = 'ART-001',
        string $name = 'Portable 14"',
        string $price = '1250',
        string $unit = 'C62',
        ?Uuid $unitId = null,
        ?Uuid $categoryId = null,
        ?array $taxes = null,
        ?array $taxIds = null,
        bool $active = true,
        array $customFields = [],
        ?Company $company = null,
        ProductKind $kind = ProductKind::Goods,
        array $barcodes = [],
        ?ProductTracking $tracking = null,
    ): ProductInput {
        $company ??= $this->company;

        return new ProductInput(
            $reference,
            new ProductDetails($name, null, $kind, $price, null),
            $unitId ?? $this->unit($unit, $company)->getId(),
            $categoryId,
            $taxIds ?? array_map(fn (string $code): Uuid => $this->tax($code, $company)->getId(), $taxes ?? ['TVA19']),
            $active,
            $customFields,
            $barcodes,
            $tracking,
        );
    }

    private function unit(string $code, ?Company $company = null): Unit
    {
        $unit = $this->units->ofCodeInCompany($code, ($company ?? $this->company)->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function tax(string $code, ?Company $company = null): TaxComponent
    {
        $tax = $this->taxes->ofCodeInCompany($code, ($company ?? $this->company)->getId());
        self::assertNotNull($tax);

        return $tax;
    }
}

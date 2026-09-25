<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Import;

use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldDefinitionRepository;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldType;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\UnitRepository;
use App\ImportExport\Application\DeclaresImport;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportHeading;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\ImportRecord;
use App\ImportExport\Application\ImportSubject;
use App\ImportExport\Application\RowImported;
use App\ImportExport\Application\RowRejected;
use App\Module\Products\Application\BarcodeInput;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Application\ProductBarcodeTaken;
use App\Module\Products\Application\ProductHomes;
use App\Module\Products\Application\ProductInput;
use App\Module\Products\Application\ProductReferenceTaken;
use App\Module\Products\Application\ProductReorderPoints;
use App\Module\Products\Application\ReorderPointRefused;
use App\Module\Products\Domain\BarcodeRole;
use App\Module\Products\Domain\InvalidProduct;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductBarcode;
use App\Module\Products\Domain\ProductCategoryRepository;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What a product file holds, for one company, and what one of its rows does (docs/SPEC.md § 8 row 59).
 *
 * A row names its unit by the code its company knows it under and its category by name, because that is what a person
 * filling in a spreadsheet has in front of them; ids belong to the API, not to a file. The custom fields a company has
 * defined for a product are its own, so they are read per company and a template is generated rather than shipped.
 *
 * A row is written through ManageProducts, the use case the product form uses, so a file is held to every rule a
 * person is — including the one that refuses a unit change once stock has moved. A row whose reference is a product's
 * already updates it in upsert mode, from the cells the row fills in only: a blank cell keeps what is there.
 */
final readonly class ProductImport implements DeclaresImport
{
    public const string KEY = 'products';

    /** A custom field's key is the company's own, so it is prefixed to keep it out of the fixed columns' namespace. */
    public const string CUSTOM_PREFIX = 'custom.';

    /** The column each field a refusal names is read from, and the code that refusal carries. */
    private const array REFUSAL_OF = [
        'reference' => ['reference', 'invalid_reference'],
        'name' => ['name', 'invalid_name'],
        'description' => ['description', 'invalid_description'],
        'unitPriceNet' => ['unit_price_net', 'invalid_price'],
        'costPrice' => ['cost_price', 'invalid_price'],
        'barcode' => ['barcode', 'invalid_barcode'],
        'unitId' => ['unit_code', 'unknown_unit'],
        'categoryId' => ['category', 'unknown_category'],
        'defaultTaxComponentIds' => ['default_tax_codes', 'unknown_tax_code'],
    ];

    private const array YES = ['yes', 'y', 'true', '1', 'oui', 'o'];
    private const array NO = ['no', 'n', 'false', '0', 'non'];

    public function __construct(
        private CustomFieldDefinitionRepository $customFields,
        private ManageProducts $manage,
        private ProductRepository $products,
        private ProductCategoryRepository $categories,
        private UnitRepository $units,
        private TaxComponentRepository $taxes,
        private EntityManagerInterface $entityManager,
        private ProductHomes $homes,
        private ProductReorderPoints $reorderPoints,
        private EstablishmentRepository $establishments,
        private CompanyGuard $guard,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function permission(): string
    {
        return ProductPermission::WRITE;
    }

    public function module(): string
    {
        return ProductsModule::KEY;
    }

    public function identityColumns(): array
    {
        return ['reference'];
    }

    public function subjectFor(Company $company): ImportSubject
    {
        return new ImportSubject(self::KEY, [...$this->fixed($company), ...$this->custom($company)]);
    }

    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId): RowImported
    {
        $reference = $record->value('reference') ?? throw new RowRejected('reference', 'A product is found again by its reference, so every row needs one.', 'value_required');
        $existing = $this->products->ofReferenceInCompany($reference, $company->getId());
        if (null !== $existing && ImportMode::Create === $mode) {
            throw new RowRejected('reference', 'A product already has this reference. Import in "create and update" mode to update it.', 'already_exists');
        }

        // Resolved BEFORE anything is written, where every other cell this row could be refused for is resolved: a
        // refusal that fires after the product is created is a rule the file is held to in a different order from
        // the rest, and only the whole import being rolled back keeps that from showing.
        $home = $this->homeOf($company, $record);
        $reorderPoint = $this->reorderPointOf($company, $record, $home);

        $written = null;
        try {
            if (null === $existing) {
                $written = $this->manage->create($company, $this->input($company, $record, null), $actorUserId);
                $this->settleHome($company, $written, $home, $actorUserId);
                $this->settleReorderPoint($company, $written, $reorderPoint, $actorUserId);

                return RowImported::Created;
            }
            $written = $this->manage->revise($company, $existing->getId(), $this->input($company, $record, $existing), $actorUserId);
            $this->settleHome($company, $written, $home, $actorUserId);
            $this->settleReorderPoint($company, $written, $reorderPoint, $actorUserId);

            return RowImported::Updated;
        } catch (InvalidProduct $refused) {
            // The one code a file writes is the unit code, first in the list: every refusal of a row of it is that cell's.
            $field = str_starts_with($refused->field, 'barcodes.') ? 'barcode' : $refused->field;
            [$column, $code] = self::REFUSAL_OF[$field] ?? [null, 'invalid_value'];

            throw new RowRejected($column, $refused->getMessage(), $code);
        } catch (ReorderPointRefused $refused) {
            throw new RowRejected('reorder_point', $refused->getMessage(), 'invalid_value');
        } catch (ProductReferenceTaken) {
            throw new RowRejected('reference', 'A product already has this reference.', 'already_exists');
        } catch (ProductBarcodeTaken $taken) {
            // Named, not merely refused: the file's author needs to know which of their products already carries
            // the code, and the row they are looking at does not say it.
            throw new RowRejected('barcode', $taken->getMessage(), 'already_exists', ['reference' => $taken->heldBy]);
        } finally {
            // Doctrine's batch processing: every flush walks every managed entity, so a row's product stays out of the
            // unit of work once written, or a file costs the square of its length. Nothing reads it back here.
            foreach ([$existing, $written] as $product) {
                if (null !== $product) {
                    $this->entityManager->detach($product);
                }
            }
        }
    }

    /**
     * Where the row says this product normally lives (docs/SPEC.md row 101), as a location the company has. A blank
     * cell keeps whatever home the product has, like every other cell in upsert mode: a file that does not mention
     * homes does not clear them.
     *
     * The location is named by its CODE, which is one place per establishment — so the establishment falls out of
     * it and is never a column of its own. A code two establishments both use cannot say which, and guessing would
     * point the product at the wrong building, so the row is rejected naming the cell.
     *
     * @throws RowRejected
     */
    private function homeOf(Company $company, ImportRecord $record): ?Uuid
    {
        $code = $record->value('home_location');
        if (null === $code || '' === trim($code)) {
            return null;
        }
        $found = $this->homes->locationsCoded($company, trim($code));
        if ([] === $found) {
            throw new RowRejected('home_location', \sprintf('The company has no stock location coded "%s". Create it first, under Stock.', $code), 'unknown_location', ['code' => $code]);
        }
        if (1 !== \count($found)) {
            throw new RowRejected('home_location', \sprintf('Several establishments have a location coded "%s", so this file cannot say which one. Rename one of them, or import one establishment at a time.', $code), 'ambiguous_location', ['code' => $code]);
        }

        return $found[0];
    }

    /** Gives the written product the home the row named, once there is a product to give it to. */
    private function settleHome(Company $company, Product $product, ?Uuid $locationId, ?Uuid $actorUserId): void
    {
        if (null !== $locationId) {
            $this->homes->setHome($company, $product->getId(), $locationId, $actorUserId);
        }
    }

    /**
     * The reorder point the row sets, and the establishment it is kept for (docs/SPEC.md § 7, 2026-09-24 11:40): the
     * establishment of the row's home location when it names one, else the company's only establishment. A company
     * with several and a row naming no home cannot say which, so the row is rejected naming the cell rather than
     * the point landing in a building nobody chose (PROVISIONAL, § 7). A blank cell keeps what is there.
     *
     * The quantity's shape is checked here with the rest of the row; whether the product's unit can count it is the
     * inventory's rule, answered once the product is written.
     *
     * @return array{Uuid, string}|null
     *
     * @throws RowRejected
     */
    private function reorderPointOf(Company $company, ImportRecord $record, ?Uuid $homeLocationId): ?array
    {
        $quantity = self::decimal($record->value('reorder_point'));
        if (null === $quantity || '' === trim($quantity)) {
            return null;
        }
        $quantity = trim($quantity);
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/', $quantity)) {
            throw new RowRejected('reorder_point', 'A reorder point is a quantity from 0, with at most three decimals.', 'invalid_value');
        }
        $establishmentId = null === $homeLocationId ? null : $this->reorderPoints->establishmentOfLocation($company, $homeLocationId);
        if (null === $establishmentId) {
            $establishments = $this->establishments->ofCompany($company->getId());
            if (1 !== \count($establishments)) {
                throw new RowRejected('reorder_point', 'The company has several establishments, so a reorder point needs the row\'s home_location to say which one.', 'ambiguous_establishment');
            }
            $establishmentId = $establishments[0]->getId();
        }

        return [$establishmentId, $quantity];
    }

    /**
     * @param array{Uuid, string}|null $point
     *
     * @throws ReorderPointRefused
     */
    private function settleReorderPoint(Company $company, Product $product, ?array $point, ?Uuid $actorUserId): void
    {
        if (null !== $point) {
            $this->reorderPoints->setReorderPoint($company, $product->getId(), $point[0], $point[1], $actorUserId);
        }
    }

    /**
     * The product the row describes: every cell it fills in, and for an existing product what it already holds
     * wherever the row leaves a cell blank.
     *
     * @throws InvalidProduct
     * @throws RowRejected
     */
    private function input(Company $company, ImportRecord $record, ?Product $current): ProductInput
    {
        $held = $current?->getDetails();
        $kind = $record->value('kind');
        $price = self::decimal($record->value('unit_price_net')) ?? $held?->unitPriceNet;

        return new ProductInput(
            $record->value('reference') ?? '',
            new ProductDetails(
                $record->value('name') ?? $held->name ?? '',
                $record->value('description') ?? $held?->description,
                null === $kind ? ($held->kind ?? ProductKind::Goods) : (ProductKind::tryFrom(strtolower($kind)) ?? throw new RowRejected('kind', 'A product is "goods" or a "service".', 'not_one_of', ['choices' => implode(', ', array_column(ProductKind::cases(), 'value'))])),
                $price ?? throw new RowRejected('unit_price_net', 'A product is sold at a price, so a new one needs one.', 'value_required'),
                ($this->guard->may($company, ProductPermission::COST_READ) ? self::decimal($record->value('cost_price')) : null) ?? $held?->costPrice,
            ),
            $this->unitId($company, $record, $current),
            $this->categoryId($company, $record, $current),
            $this->taxIds($company, $record, $current),
            self::yesNo($record, 'active') ?? $current?->isActive() ?? true,
            $this->customValues($company, $record, $current),
            self::barcodes($record, $current),
        );
    }

    /**
     * The file's one code is the product's UNIT code (docs/SPEC.md § 7, 2026-09-22 11:05): given, it becomes the
     * unit row, first; its packs, supplier and internal codes are kept, since the file has no column for them and a
     * blank is not an instruction to remove them. Left blank, every code stays as it is.
     *
     * @return list<BarcodeInput>
     */
    private static function barcodes(ImportRecord $record, ?Product $current): array
    {
        $held = array_map(
            static fn (ProductBarcode $row): BarcodeInput => new BarcodeInput($row->getRole()->value, $row->getCode(), $row->getQuantity(), $row->getSupplier()?->getId()),
            $current?->getBarcodes() ?? [],
        );
        $unit = $record->value('barcode');
        if (null === $unit) {
            return $held;
        }

        return [
            new BarcodeInput(BarcodeRole::Unit->value, $unit, 1),
            ...array_values(array_filter($held, static fn (BarcodeInput $row): bool => BarcodeRole::Unit->value !== $row->role)),
        ];
    }

    /** The unit the row names by code. A product is sold in one, so a row creating one names it. */
    private function unitId(Company $company, ImportRecord $record, ?Product $current): Uuid
    {
        $code = $record->value('unit_code');
        if (null === $code) {
            return $current?->getUnit()->getId()
                ?? throw new RowRejected('unit_code', 'A product is sold in a unit, so a new one names its code.', 'value_required');
        }

        return $this->units->ofCodeInCompany($code, $company->getId())?->getId()
            ?? throw new RowRejected('unit_code', \sprintf('The company has no unit coded "%s".', $code), 'unknown_unit', ['code' => $code]);
    }

    private function categoryId(Company $company, ImportRecord $record, ?Product $current): ?Uuid
    {
        $name = $record->value('category');
        if (null === $name) {
            return $current?->getCategory()?->getId();
        }

        return $this->categories->ofNameInCompany($name, $company->getId())?->getId()
            ?? throw new RowRejected('category', \sprintf('The company has no product category named "%s". Create it first.', $name), 'unknown_category', ['name' => $name]);
    }

    /** @return list<Uuid> */
    private function taxIds(Company $company, ImportRecord $record, ?Product $current): array
    {
        $codes = $record->value('default_tax_codes');
        if (null === $codes) {
            return array_map(Uuid::fromString(...), $current?->getDefaultTaxComponentIds() ?? []);
        }

        $ids = [];
        foreach (preg_split('/[\s;,]+/', $codes, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
            $ids[] = $this->taxes->ofCodeInCompany($code, $company->getId())?->getId()
                ?? throw new RowRejected('default_tax_codes', \sprintf('The company has no tax coded "%s".', $code), 'unknown_tax_code', ['code' => $code]);
        }

        return $ids;
    }

    /**
     * The row's custom field cells, typed as the form sends them, over the values held.
     *
     * @return array<string, string|int|float|bool>
     */
    private function customValues(Company $company, ImportRecord $record, ?Product $current): array
    {
        $values = $current?->getCustomFields() ?? [];
        foreach ($this->customFields->ofCompanyAndEntity($company->getId(), CustomFieldEntity::Product) as $field) {
            $column = self::CUSTOM_PREFIX.$field->getKey();
            $cell = $record->value($column);
            if (null === $cell || !$field->isActive()) {
                continue;
            }
            $values[$field->getKey()] = match ($field->getType()) {
                CustomFieldType::Number => self::number($cell) ?? throw new RowRejected($column, 'A number.', 'not_a_number'),
                CustomFieldType::Bool => self::yesNo($record, $column) ?? throw new RowRejected($column, 'Yes or no.', 'not_yes_or_no'),
                default => $cell,
            };
        }

        return $values;
    }

    /**
     * The columns every company has. `reference` is required because a product is found again by it on a second
     * import. A unit and a price are what a product cannot be CREATED without, which is a rule on the row and not on
     * the file: a file updating nothing but a name carries neither column, and requiring them here would refuse it.
     *
     * `home_location` is offered only to a company that holds stock, since a company without it has nowhere to put a
     * product: its file is not asked for a shelf, and a file naming one is refused for the column rather than having
     * the cell quietly dropped. `cost_price` is offered, the same way, only to someone who may read costs
     * (product.cost.read, docs/SPEC.md § 7, 2026-09-23 09:45): a file from anyone else naming it is refused for the
     * column, and every cost stays as it is.
     *
     * @return list<ImportColumn>
     */
    private function fixed(Company $company): array
    {
        return [
            new ImportColumn('reference', 'import.products.reference', true, 'VIS-6X40', 'import.products.reference_note'),
            new ImportColumn('name', 'import.products.name', true, 'Vis 6x40 zinguée'),
            new ImportColumn('kind', 'import.products.kind', false, 'goods', 'import.products.kind_note'),
            new ImportColumn('description', 'import.products.description'),
            new ImportColumn('unit_code', 'import.products.unit_code', false, 'H87', 'import.products.unit_code_note'),
            new ImportColumn('category', 'import.products.category', false, null, 'import.products.category_note'),
            new ImportColumn('unit_price_net', 'import.products.unit_price_net', false, '0.4500', 'import.products.price_note'),
            ...$this->guard->may($company, ProductPermission::COST_READ)
                ? [new ImportColumn('cost_price', 'import.products.cost_price', false, '0.2200', 'import.products.cost_note')]
                : [],
            new ImportColumn('barcode', 'import.products.barcode', false, '6191234567897', 'import.products.barcode_note'),
            new ImportColumn('default_tax_codes', 'import.products.default_taxes', false, null, 'import.products.default_taxes_note'),
            new ImportColumn('active', 'import.products.active', false, 'yes', 'import.boolean_note'),
            ...$this->homes->offered($company)
                ? [
                    new ImportColumn('home_location', 'import.products.home_location', false, 'A-12', 'import.products.home_location_note'),
                    new ImportColumn('reorder_point', 'import.products.reorder_point', false, '12', 'import.products.reorder_point_note'),
                ]
                : [],
        ];
    }

    /**
     * This company's own custom fields for a product, active ones only: a field switched off is not asked for in a
     * file a person is about to fill in.
     *
     * @return list<ImportColumn>
     */
    private function custom(Company $company): array
    {
        $columns = [];
        foreach ($this->customFields->ofCompanyAndEntity($company->getId(), CustomFieldEntity::Product) as $field) {
            if (!$field->isActive()) {
                continue;
            }

            $columns[] = new ImportColumn(
                self::CUSTOM_PREFIX.$field->getKey(),
                $field->getLabel(),
                $field->isRequired(),
                self::exampleOf($field),
                headingIs: ImportHeading::Label,
            );
        }

        return $columns;
    }

    private static function exampleOf(CustomFieldDefinition $field): ?string
    {
        $choices = $field->getChoices();

        return [] === $choices ? null : (string) reset($choices);
    }

    /** @throws RowRejected when the cell is neither a yes nor a no */
    private static function yesNo(ImportRecord $record, string $column): ?bool
    {
        $cell = $record->value($column);
        if (null === $cell) {
            return null;
        }
        $cell = mb_strtolower($cell);

        return match (true) {
            \in_array($cell, self::YES, true) => true,
            \in_array($cell, self::NO, true) => false,
            default => throw new RowRejected($column, 'Yes or no.', 'not_yes_or_no'),
        };
    }

    /** A decimal written with a point or, as a French or Tunisian spreadsheet writes it, a comma. */
    private static function decimal(?string $cell): ?string
    {
        return null === $cell ? null : str_replace(',', '.', $cell);
    }

    private static function number(string $cell): int|float|null
    {
        $normalised = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', ''], $cell);
        if (!is_numeric($normalised)) {
            return null;
        }

        return 1 === preg_match('/^-?\d+$/', $normalised) ? (int) $normalised : (float) $normalised;
    }
}

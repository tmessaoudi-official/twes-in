<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Import;

use App\CustomFields\Domain\Model\CustomFieldDefinition;
use App\CustomFields\Domain\Repository\CustomFieldDefinitionRepository;
use App\CustomFields\Domain\ValueObject\CustomFieldEntity;
use App\CustomFields\Domain\ValueObject\CustomFieldType;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\ImportExport\Application\DeclaresImport;
use App\ImportExport\Application\ImportCells;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportContext;
use App\ImportExport\Application\ImportHeading;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\ImportRecord;
use App\ImportExport\Application\ImportSubject;
use App\ImportExport\Application\ImportSwitch;
use App\ImportExport\Application\RowFaults;
use App\ImportExport\Application\RowIdentity;
use App\ImportExport\Application\RowImported;
use App\ImportExport\Application\RowNotes;
use App\ImportExport\Application\RowRejected;
use App\Module\Products\Application\BarcodeInput;
use App\Module\Products\Application\CustomerPrice;
use App\Module\Products\Application\GrossUnreachable;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Application\ProductBarcodeTaken;
use App\Module\Products\Application\ProductFileStock;
use App\Module\Products\Application\ProductHomes;
use App\Module\Products\Application\ProductInput;
use App\Module\Products\Application\ProductReferenceTaken;
use App\Module\Products\Application\ProductReorderPoints;
use App\Module\Products\Application\ProductStockRefused;
use App\Module\Products\Application\ReorderPointRefused;
use App\Module\Products\Domain\Barcode;
use App\Module\Products\Domain\BarcodeLine;
use App\Module\Products\Domain\BarcodeRole;
use App\Module\Products\Domain\InvalidProduct;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductCategoryRepository;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Domain\ProductTracking;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Module\Vendors\Domain\VendorRepository;
use App\ModuleRegistry\Application\ModuleStates;
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
    /** Reuses a catalogue file for its prices alone: its quantities are not read. */
    public const string IGNORE_QUANTITIES = 'ignore_quantities';
    /** Counts again a place where goods came in or went out since its last count. */
    public const string RECOUNT = 'recount';

    /** The column each field a refusal names is read from, and the code that refusal carries when it says none. */
    private const array REFUSAL_OF = [
        'reference' => ['reference', 'invalid_reference'],
        'kind' => ['kind', 'invalid_value'],
        'name' => ['name', 'invalid_name'],
        'description' => ['description', 'invalid_description'],
        'unitPriceNet' => ['unit_price_net', 'invalid_price'],
        'costPrice' => ['cost_price', 'invalid_price'],
        'barcode' => ['barcode', 'invalid_barcode'],
        'unitId' => ['unit_code', 'unknown_unit'],
        'categoryId' => ['category', 'unknown_category'],
        'defaultTaxComponentIds' => ['default_tax_codes', 'unknown_tax_code'],
        'substitutionGroup' => ['substitution_group', 'invalid_text'],
        'tracking' => ['tracking', 'invalid_value'],
    ];

    /** What the inventory answers for: where goods lie, the reorder point and the stock, compared through its ports. */
    private const array STOCK_COLUMNS = ['home_location', 'reorder_point', 'stock_add', 'stock_count', 'location'];
    private const array YES = ['yes', 'y', 'true', '1', 'oui', 'o'];
    /** A kind by its code or as the screen names it, in French and in English. */
    private const array KINDS = ['goods' => 'goods', 'bien' => 'goods', 'biens' => 'goods', 'service' => 'service', 'services' => 'service'];
    private const array NO = ['no', 'n', 'false', '0', 'non'];
    /** How many pieces a pack holds, as `BarcodeLine` holds it. */
    private const array PACK_BOUNDS = ['min' => 2, 'max' => BarcodeLine::QUANTITY_MAX];
    /** How stock is told apart, by its code or as the screen says it, in French and in English. */
    private const array TRACKINGS = [
        'none' => 'none', 'aucun' => 'none', 'sans suivi' => 'none',
        'lot' => 'lot', 'par lot' => 'lot',
        'serial' => 'serial', 'série' => 'serial', 'serie' => 'serial', 'numéro de série' => 'serial', 'par numéro de série' => 'serial',
    ];

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
        private ProductFileStock $stock,
        private VendorRepository $vendors,
        private ModuleStates $modules,
        private CustomerPrice $prices,
        private CurrencyScales $scales,
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

    /**
     * Its reference, else its unit code as the scanner reads it: a row that leaves the reference out is found again by
     * the code, so two such rows naming one code are one product twice.
     */
    public function identityOf(Company $company, ImportRecord $record): ?RowIdentity
    {
        $code = $record->value('barcode');

        return RowIdentity::ofColumns($record, ['reference'])
            ?? (null === $code ? null : new RowIdentity('barcode', "barcode\x1f".Barcode::keyOf($code), 'barcode'));
    }

    public function finished(Company $company, ImportContext $context, ?Uuid $actorUserId): void
    {
        $this->stock->finished($company, $context->runId, $actorUserId);
    }

    /**
     * The quantities, `location` and the two switches exist only for someone who may write stock while the company
     * keeps it: a file from anyone else naming them is refused for the column, as `cost_price` is.
     */
    public function subjectFor(Company $company): ImportSubject
    {
        $stock = $this->stock->offered($company);

        return new ImportSubject(
            self::KEY,
            [
                ...$this->fixed($company),
                ...$stock
                    ? [
                        new ImportColumn('stock_add', 'import.stock.stock_add', false, '24', 'import.stock.stock_add_note'),
                        new ImportColumn('stock_count', 'import.stock.stock_count', false, '120', 'import.stock.stock_count_note'),
                        new ImportColumn('location', 'import.products.location', false, 'A-12', 'import.products.location_note', code: true),
                    ]
                    : [],
                ...$this->custom($company),
            ],
            $stock ? [
                new ImportSwitch(self::IGNORE_QUANTITIES, 'import.stock.ignore_quantities', 'import.stock.ignore_quantities_note'),
                new ImportSwitch(self::RECOUNT, 'import.stock.recount', 'import.stock.recount_note'),
            ] : [],
        );
    }

    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId, RowNotes $notes, ImportContext $context): RowImported
    {
        // Found again by the reference written in the row, else by the unit code written in it, never by its name
        // (docs/SPEC.md § 7, 2026-09-17 (3)).
        $reference = $record->value('reference');
        $existing = null === $reference ? $this->ofUnitCode($company, $record->value('barcode')) : $this->products->ofReferenceInCompany($reference, $company->getId());
        if (null !== $existing && ImportMode::Create === $mode) {
            throw null === $reference ? new RowRejected('barcode', 'A product already answers to this code. Import in "create and update" mode to update it.', 'already_exists', ['reference' => $existing->getReference()]) : new RowRejected('reference', 'A product already has this reference. Import in "create and update" mode to update it.', 'already_exists');
        }
        if (null === $existing) {
            // A new product whose name another already holds is pointed out, as it may be that product under another
            // reference; the more so when nothing in the row can find it again, and a second import would make it twice.
            $namesake = $this->products->ofNameInCompany($record->value('name') ?? '', $company->getId());
            if (null !== $namesake) {
                $notes->note('name', null === $reference && null === $record->value('barcode') ? 'name_shared' : 'name_held', ['reference' => $namesake->getReference()]);
            }
        }

        // Resolved BEFORE anything is written, where every other cell this row could be refused for is resolved: a
        // refusal that fires after the product is created is a rule the file is held to in a different order from
        // the rest, and only the whole import being rolled back keeps that from showing. Every fault found is said
        // at once (ruling 9); a home that cannot be found stands as `false`, so the reorder point is not refused for
        // want of it.
        $faults = new RowFaults();
        $home = $faults->read(fn (): ?Uuid => $this->homeOf($company, $record), false);
        $reorderPoint = $faults->read(fn (): ?array => $this->reorderPointOf($company, $record, $home));
        $quantity = $faults->read(fn (): ?array => $this->quantityOf($record, $context));

        $seesCosts = $this->guard->may($company, ProductPermission::COST_READ);
        $codes = $this->codes($company, $record, $existing, $seesCosts, $faults);

        $written = null;
        try {
            $input = $this->input($company, $record, $existing, $faults, array_column($codes, 0), $seesCosts);
            $faults->stopIfAny();
            \assert(false !== $home);
            // Rendered before the write, which changes the product in place.
            $before = null === $existing ? [] : $this->inFile($company, $existing, $context, $record);
            if (null === $existing) {
                $written = $this->manage->create($company, $input, $actorUserId);
                $this->settleHome($company, $written, $home, $actorUserId);
                $this->settleReorderPoint($company, $written, $reorderPoint, $actorUserId);
                if (null === $reference) {
                    $notes->note('reference', 'reference_given', ['reference' => $written->getReference()]);
                }
                $this->settleStock($company, $written, $record, $quantity, $home, $actorUserId, $context, $notes);

                return RowImported::Created;
            }
            $written = $this->manage->revise($company, $existing->getId(), $input, $actorUserId);
            $after = $this->inFile($company, $written, $context, $record);
            $moved = [
                'home_location' => $this->settleHome($company, $written, $home, $actorUserId),
                'reorder_point' => $this->settleReorderPoint($company, $written, $reorderPoint, $actorUserId),
            ];
            $stock = $this->settleStock($company, $written, $record, $quantity, $home, $actorUserId, $context, $notes);
            if (null !== $stock) {
                $moved[$stock[0]] = [$stock[1], $stock[2]];
            }
            foreach (array_filter($moved) as $column => [$was, $now]) {
                $before[$column] = $was;
                $after[$column] = $now;
            }

            return $notes->changedBetween($before, $after) ? RowImported::Updated : RowImported::Unchanged;
        } catch (InvalidProduct $refused) {
            if (1 === preg_match('/^barcodes\.(\d+)\.(\w+)$/', $refused->field, $at)) {
                // A code's refusal is the cell it came from, the product's own codes the file did not name the unit code's.
                [$column, $code] = 'quantity' === $at[2]
                    ? [$codes[(int) $at[1]][1]['quantity'] ?? 'barcode', 'invalid_pack_quantity']
                    : [$codes[(int) $at[1]][1][$at[2]] ?? $codes[(int) $at[1]][1]['code'] ?? 'barcode', 'code' === $at[2] ? 'invalid_barcode' : 'invalid_value'];

                throw new RowRejected($column, $refused->getMessage(), $refused->reason ?? $code, 'invalid_pack_quantity' === $code ? self::PACK_BOUNDS : $refused->params);
            }
            [$column, $code] = str_starts_with($refused->field, 'customFields.')
                ? [self::CUSTOM_PREFIX.substr($refused->field, \strlen('customFields.')), 'invalid_value']
                : self::REFUSAL_OF[$refused->field] ?? [null, 'invalid_value'];

            throw new RowRejected($column, $refused->getMessage(), $refused->reason ?? $code, $refused->params);
        } catch (ProductStockRefused $refused) {
            throw new RowRejected(match ($refused->about) {
                ProductStockRefused::PLACE => null === $record->value('location') && null !== $home ? 'home_location' : 'location', ProductStockRefused::COST => 'cost_price', default => $quantity[2] ?? 'stock_count',
            }, $refused->getMessage(), $refused->reason, $refused->params);
        } catch (ReorderPointRefused $refused) {
            throw new RowRejected('reorder_point', $refused->getMessage(), 'invalid_value');
        } catch (ProductReferenceTaken) {
            throw new RowRejected('reference', 'A product already has this reference.', 'already_exists');
        } catch (ProductBarcodeTaken $taken) {
            // Named, not merely refused: the file's author needs to know which of their products already carries
            // the code, and the row they are looking at does not say it. Another mode would not help: it is that product's.
            throw new RowRejected($codes[$taken->index][1]['code'] ?? 'barcode', $taken->getMessage(), 'barcode_taken', ['reference' => $taken->heldBy]);
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

    /** The product whose UNIT code the cell writes, the one code a file carries; a pack's or a supplier's is not it. */
    private function ofUnitCode(Company $company, ?string $code): ?Product
    {
        if (null === $code) {
            return null;
        }
        $held = $this->products->barcodeOfKeyInCompany(Barcode::keyOf($code), $company->getId());

        return null !== $held && BarcodeRole::Unit === $held->getRole() ? $held->getProduct() : null;
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

    /**
     * What the row asks of the stock, checked before anything is written: an addition or a count, never both, and
     * nothing when the cells are empty or the person ticked « Ignorer les quantités de ce fichier ».
     *
     * @return array{bool, string, string}|null whether it adds, the quantity, and the column it is written in
     *
     * @throws RowRejected
     */
    private function quantityOf(ImportRecord $record, ImportContext $context): ?array
    {
        if ($context->ticked(self::IGNORE_QUANTITIES)) {
            return null;
        }
        $add = ImportCells::decimal($record, 'stock_add', 3);
        $count = ImportCells::decimal($record, 'stock_count', 3);
        if (null !== $add && null !== $count) {
            throw new RowRejected('stock_count', 'A row adds goods or counts them, not both: keep one of stock_add and stock_count.', 'stock_add_and_count');
        }

        return match (true) {
            null !== $add => [true, $add, 'stock_add'],
            null !== $count => [false, $count, 'stock_count'],
            default => null,
        };
    }

    /**
     * Adds or counts the written product's stock, at its cost as it stands after the row, so a row giving a cost
     * enters its goods at that cost.
     *
     * @param array{bool, string, string}|null $quantity
     *
     * @return array{string, string, string}|null the column the quantity is written in, and what is on hand there before and after
     *
     * @throws ProductStockRefused
     */
    private function settleStock(Company $company, Product $product, ImportRecord $record, ?array $quantity, ?Uuid $home, ?Uuid $actorUserId, ImportContext $context, RowNotes $notes): ?array
    {
        if (null === $quantity) {
            return null;
        }
        [$adds, $value, $column] = $quantity;
        $location = $record->value('location');
        $change = $adds
            ? $this->stock->add($company, $product->getId(), $location, $home, $value, $product->getDetails()->costPrice, $actorUserId, $context->runId)
            : $this->stock->count($company, $product->getId(), $location, $home, $value, $context->ticked(self::RECOUNT), $actorUserId, $context->runId);
        $notes->note($column, 'stock_change', ['location' => $change->place, 'before' => $change->before, 'after' => $change->after]);

        return [$column, $change->before, $change->after];
    }

    /**
     * Gives the written product the home the row named, once there is a product to give it to.
     *
     * @return array{string, string}|null the main home's code there before and after, when the row names one
     */
    private function settleHome(Company $company, Product $product, ?Uuid $locationId, ?Uuid $actorUserId): ?array
    {
        return null === $locationId ? null : $this->homes->setHome($company, $product->getId(), $locationId, $actorUserId);
    }

    /**
     * The product as the file writes it, over the columns this person's file has but those of the stock, which the
     * inventory answers for (docs/SPEC.md § 7, 2026-10-09 10:40, ruling 5).
     *
     * @return array<string, string> by column
     */
    private function inFile(Company $company, Product $product, ImportContext $context, ImportRecord $record): array
    {
        $codes = [];
        foreach ($this->taxes->ofCompany($company->getId()) as $tax) {
            $codes[$tax->getId()->toRfc4122()] = $tax->getCode();
        }
        $cells = [];
        foreach (array_diff($context->columns, self::STOCK_COLUMNS) as $column) {
            $cells[$column] = ProductInFile::cell($product, $column, $codes, $record->value('pack_barcode'), $record->value('supplier_code'));
        }

        return $cells;
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
     * A home the row names but the company has not (`false`) leaves the point's shape checked and nothing more.
     *
     * @return array{Uuid, string}|null
     *
     * @throws RowRejected
     */
    private function reorderPointOf(Company $company, ImportRecord $record, Uuid|false|null $homeLocationId): ?array
    {
        $quantity = ImportCells::decimal($record, 'reorder_point', 3);
        if (null === $quantity || '' === trim($quantity)) {
            return null;
        }
        $quantity = trim($quantity);
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/', $quantity)) {
            throw new RowRejected('reorder_point', 'A reorder point is a quantity from 0, with at most three decimals.', 'invalid_value');
        }
        if (false === $homeLocationId) {
            return null;
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
     * @return array{string, string}|null the point before and after, when the row sets one
     *
     * @throws ReorderPointRefused
     */
    private function settleReorderPoint(Company $company, Product $product, ?array $point, ?Uuid $actorUserId): ?array
    {
        return null === $point ? null : $this->reorderPoints->setReorderPoint($company, $product->getId(), $point[0], $point[1], $actorUserId);
    }

    /**
     * The product the row describes: every cell it fills in, and for an existing product what it already holds
     * wherever the row leaves a cell blank.
     *
     * A refused cell is kept among the row's faults and leaves what the product holds, or a stand-in, in its place:
     * the row is stopped before the input is used.
     *
     * @throws InvalidProduct
     */
    /** @param list<BarcodeInput> $codes */
    private function input(Company $company, ImportRecord $record, ?Product $current, RowFaults $faults, array $codes, bool $seesCosts): ProductInput
    {
        $held = $current?->getDetails();
        $kind = $record->value('kind');
        $taxIds = $faults->read(fn (): array => $this->taxIds($company, $record, $current), false);
        $price = $faults->read(fn (): string => $this->priceOf($company, $record, $held, $taxIds), '0');

        return new ProductInput(
            // Left out, a new product is given the next of the company's format and a product found by its code keeps its own.
            $record->value('reference') ?? $current?->getReference() ?? '',
            new ProductDetails(
                $record->value('name') ?? $held->name ?? '',
                $record->value('description') ?? $held?->description,
                null === $kind ? ($held->kind ?? ProductKind::Goods) : $faults->read(static fn (): ProductKind => ProductKind::tryFrom(self::KINDS[mb_strtolower(trim($kind))] ?? '') ?? throw new RowRejected('kind', 'A product is "goods" or a "service".', 'not_one_of', ['choices' => implode(', ', array_column(ProductKind::cases(), 'value'))]), ProductKind::Goods),
                $price,
                ($this->guard->may($company, ProductPermission::COST_READ) ? $faults->read(static fn (): ?string => ImportCells::decimal($record, 'cost_price', 4)) : null) ?? $held?->costPrice,
                $record->value('substitution_group') ?? $held?->substitutionGroup,
            ),
            $faults->read(fn (): Uuid => $this->unitId($company, $record, $current), Uuid::v7()),
            $faults->read(fn (): ?Uuid => $this->categoryId($company, $record, $current), $current?->getCategory()?->getId()),
            false === $taxIds ? [] : $taxIds,
            $faults->read(static fn (): ?bool => self::yesNo($record, 'active')) ?? $current?->isActive() ?? true,
            $this->customValues($company, $record, $current, $faults),
            $codes,
            // Left blank, the product keeps its own; a new one follows what its category or company says.
            $faults->read(static fn (): ?ProductTracking => self::tracking($record)),
            // Somebody who sees no cost is shown no supplier's code, so the product form's rule keeps them as stored.
            seesCosts: $seesCosts,
        );
    }

    /**
     * The net price the row sets: written as it is, or from the price taxes included a counter thinks in (F26), never
     * both, as the two could disagree. A price taxes included is counted over the row's taxes, else the product's;
     * taxes the row names wrongly are refused on their own cell and leave it unjudged.
     *
     * @param list<Uuid>|false $taxIds false when the row's taxes were refused
     *
     * @throws RowRejected
     */
    private function priceOf(Company $company, ImportRecord $record, ?ProductDetails $held, array|false $taxIds): string
    {
        $net = ImportCells::decimal($record, 'unit_price_net', 4);
        if (null === $record->value('unit_price_gross')) {
            return $net ?? $held->unitPriceNet
                ?? throw new RowRejected('unit_price_net', 'A product is sold at a price, so a new one needs one.', 'value_required');
        }
        if (null !== $net) {
            throw new RowRejected('unit_price_gross', 'A row gives its price without taxes or with them, not both.', 'price_net_and_gross');
        }
        $gross = (string) ImportCells::decimal($record, 'unit_price_gross', $this->scales->of($company->getCurrency()));
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,9})(\.[0-9]+)?$/', $gross)) {
            throw new RowRejected('unit_price_gross', 'A price is a number from 0.', 'invalid_price');
        }
        if (false === $taxIds) {
            return $held->unitPriceNet ?? '0';
        }
        try {
            return $this->prices->netOf($company, array_map(static fn (Uuid $id): string => $id->toRfc4122(), $taxIds), $gross);
        } catch (InvalidProduct) {
            // A tax charged on a document rather than on a line: the product's rules refuse it on its own cell.
            return $held->unitPriceNet ?? '0';
        } catch (GrossUnreachable $unreachable) {
            throw new RowRejected('unit_price_gross', $unreachable->getMessage(), 'gross_unreachable', ['gross' => $gross, 'below' => $unreachable->below, 'above' => $unreachable->above]);
        }
    }

    /** @throws RowRejected */
    private static function tracking(ImportRecord $record): ?ProductTracking
    {
        $cell = $record->value('tracking');
        if (null === $cell) {
            return null;
        }

        return ProductTracking::tryFrom(self::TRACKINGS[mb_strtolower(trim($cell))] ?? '')
            ?? throw new RowRejected('tracking', 'Stock is told apart by "none", "lot" or "serial".', 'not_one_of', ['choices' => implode(', ', array_column(ProductTracking::cases(), 'value'))]);
    }

    /**
     * The codes the product is written with (docs/SPEC.md § 7, 2026-09-22 11:05, F26): the unit code the row gives
     * replaces the unit row and comes first; the pack and the supplier's code it gives are added, or update the one the
     * product holds under that code; nothing the row does not name is removed. A supplier's code is only for somebody
     * who sees costs: for anybody else the product's are left out here and kept as stored by the use case.
     *
     * @return list<array{BarcodeInput, array<string, string>}> each code, with the column each of its fields came from
     */
    private function codes(Company $company, ImportRecord $record, ?Product $current, bool $seesCosts, RowFaults $faults): array
    {
        $codes = [];
        foreach ($current?->getBarcodes() ?? [] as $row) {
            if ($seesCosts || BarcodeRole::Supplier !== $row->getRole()) {
                $codes[] = [new BarcodeInput($row->getRole()->value, $row->getCode(), $row->getQuantity(), $row->getSupplier()?->getId()), []];
            }
        }
        $unit = $record->value('barcode');
        if (null !== $unit) {
            $codes = [
                [new BarcodeInput(BarcodeRole::Unit->value, $unit, 1), self::cellsOf('barcode')],
                ...array_values(array_filter($codes, static fn (array $code): bool => BarcodeRole::Unit->value !== $code[0]->role)),
            ];
        }

        $pack = $record->value('pack_barcode');
        $quantity = $faults->read(static fn (): ?int => self::packQuantity($record), false);
        if (null === $pack && \is_int($quantity)) {
            $faults->read(static fn () => throw new RowRejected('pack_barcode', 'A pack\'s quantity goes with the pack\'s code.', 'value_required'));
        }
        if (null !== $pack && false !== $quantity) {
            $at = self::heldAt($codes, BarcodeRole::Pack, $pack);
            $quantity ??= null === $at ? null : $codes[$at][0]->quantity;
            if (null === $quantity) {
                $faults->read(static fn () => throw new RowRejected('pack_quantity', 'A new pack says how many pieces it holds.', 'value_required'));
            } else {
                $codes = self::placed($codes, $at, [new BarcodeInput(BarcodeRole::Pack->value, $pack, $quantity), self::cellsOf('pack_barcode', ['quantity' => 'pack_quantity'])]);
            }
        }

        $number = $record->value('supplier_number');
        $code = $record->value('supplier_code');
        if (null !== $number || null !== $code) {
            $supplier = $faults->read(fn (): Uuid => $this->supplierId($company, $number, $code));
            if (null !== $supplier && null !== $code) {
                $codes = self::placed($codes, self::heldAt($codes, BarcodeRole::Supplier, $code), [new BarcodeInput(BarcodeRole::Supplier->value, $code, 1, $supplier), self::cellsOf('supplier_code', ['supplierId' => 'supplier_number'])]);
            }
        }

        return $codes;
    }

    /**
     * The column each field of a code came from: its code's own, and the others the row names it with.
     *
     * @param array<string, string> $others
     *
     * @return array<string, string>
     */
    private static function cellsOf(string $column, array $others = []): array
    {
        return ['code' => $column, ...$others];
    }

    /**
     * The supplier the row names by number, both halves of the pair given.
     *
     * @throws RowRejected
     */
    private function supplierId(Company $company, ?string $number, ?string $code): Uuid
    {
        if (null === $number || null === $code) {
            throw new RowRejected(null === $number ? 'supplier_number' : 'supplier_code', 'A supplier\'s code goes with the supplier\'s number.', 'value_required');
        }

        return $this->vendors->ofNumberInCompany($number, $company->getId())?->getId()
            ?? throw new RowRejected('supplier_number', \sprintf('The company has no supplier numbered "%s".', $number), 'unknown_vendor', ['number' => $number]);
    }

    /**
     * How many pieces the row's pack holds: a whole number from two, as the product form takes it.
     *
     * @throws RowRejected
     */
    private static function packQuantity(ImportRecord $record): ?int
    {
        $cell = $record->value('pack_quantity');
        if (null === $cell) {
            return null;
        }
        $digits = str_replace([' ', "\u{A0}", "\u{202F}"], '', $cell);
        if (1 !== preg_match('/^\d{1,7}$/', $digits) || (int) $digits < self::PACK_BOUNDS['min'] || (int) $digits > self::PACK_BOUNDS['max']) {
            throw new RowRejected('pack_quantity', \sprintf('A pack holds %d to %d pieces.', self::PACK_BOUNDS['min'], self::PACK_BOUNDS['max']), 'invalid_pack_quantity', self::PACK_BOUNDS);
        }

        return (int) $digits;
    }

    /** @param list<array{BarcodeInput, array<string, string>}> $codes */
    private static function heldAt(array $codes, BarcodeRole $role, string $code): ?int
    {
        $key = Barcode::keyOf($code);
        foreach ($codes as $index => [$row]) {
            if ($role->value === $row->role && $key === Barcode::keyOf($row->code)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array{BarcodeInput, array<string, string>}> $codes
     * @param array{BarcodeInput, array<string, string>}       $code
     *
     * @return list<array{BarcodeInput, array<string, string>}>
     */
    private static function placed(array $codes, ?int $at, array $code): array
    {
        if (null === $at) {
            $codes[] = $code;
        } else {
            $codes[$at] = $code;
        }

        return $codes;
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
    private function customValues(Company $company, ImportRecord $record, ?Product $current, RowFaults $faults): array
    {
        $values = $current?->getCustomFields() ?? [];
        foreach ($this->customFields->ofCompanyAndEntity($company->getId(), CustomFieldEntity::Product) as $field) {
            $column = self::CUSTOM_PREFIX.$field->getKey();
            $cell = $record->value($column);
            if (null === $cell || !$field->isActive()) {
                continue;
            }
            $values[$field->getKey()] = $faults->read(static fn (): string|int|float|bool => match ($field->getType()) {
                CustomFieldType::Number => self::number($cell) ?? throw new RowRejected($column, 'A number.', 'not_a_number'),
                CustomFieldType::Date => ImportCells::day($cell),
                CustomFieldType::Bool => self::yesNo($record, $column) ?? throw new RowRejected($column, 'Yes or no.', 'not_yes_or_no'),
                default => $cell,
            }, $cell);
        }

        return $values;
    }

    /**
     * The columns every company has. `reference` may be left out: such a row is found again by its unit code, and a new
     * product is given the next reference of the company's format. A unit and a price are what a product cannot be CREATED without, which is a rule on the row and not on
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
            new ImportColumn('reference', 'import.products.reference', false, 'VIS-6X40', 'import.products.reference_optional_note', code: true),
            new ImportColumn('name', 'import.products.name', true, 'Vis 6x40 zinguée'),
            new ImportColumn('kind', 'import.products.kind', false, 'goods', 'import.products.kind_note'),
            new ImportColumn('tracking', 'import.products.tracking', false, 'none', 'import.products.tracking_note'),
            new ImportColumn('description', 'import.products.description'),
            new ImportColumn('substitution_group', 'import.products.substitution_group', false, null, 'import.products.substitution_group_note'),
            new ImportColumn('unit_code', 'import.products.unit_code', false, 'H87', 'import.products.unit_code_note'),
            new ImportColumn('category', 'import.products.category', false, null, 'import.products.category_note'),
            new ImportColumn('unit_price_net', 'import.products.unit_price_net', false, '0.4500', 'import.products.price_note'),
            // No example: the template's row would then give the price twice.
            new ImportColumn('unit_price_gross', 'import.products.unit_price_gross', false, null, 'import.products.unit_price_gross_note'),
            ...$this->guard->may($company, ProductPermission::COST_READ)
                ? [new ImportColumn('cost_price', 'import.products.cost_price', false, '0.2200', 'import.products.cost_note')]
                : [],
            new ImportColumn('barcode', 'import.products.barcode', false, '6191234567897', 'import.products.barcode_note', code: true),
            new ImportColumn('pack_barcode', 'import.products.pack_barcode', false, '16191234567894', 'import.products.pack_barcode_note', code: true),
            new ImportColumn('pack_quantity', 'import.products.pack_quantity', false, '12', 'import.products.pack_quantity_note'),
            // A supplier's code is a cost reader's, and names one of the company's suppliers.
            ...$this->guard->may($company, ProductPermission::COST_READ) && $this->modules->isEnabled($company->getId(), 'vendors')
                ? [
                    new ImportColumn('supplier_number', 'import.products.supplier_number', false, 'FRN-0001', 'import.products.supplier_number_note', code: true),
                    new ImportColumn('supplier_code', 'import.products.supplier_code', false, 'SF-883', 'import.products.supplier_code_note', code: true),
                ]
                : [],
            new ImportColumn('default_tax_codes', 'import.products.default_taxes', false, null, 'import.products.default_taxes_note'),
            new ImportColumn('active', 'import.products.active', false, 'yes', 'import.boolean_note'),
            ...$this->homes->offered($company)
                ? [
                    new ImportColumn('home_location', 'import.products.home_location', false, 'A-12', 'import.products.home_location_note', code: true),
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

    private static function number(string $cell): int|float|null
    {
        $normalised = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', ''], $cell);
        if (!is_numeric($normalised)) {
            return null;
        }

        return 1 === preg_match('/^-?\d+$/', $normalised) ? (int) $normalised : (float) $normalised; // float: a custom number field is stored as a JSON number, never money
    }
}

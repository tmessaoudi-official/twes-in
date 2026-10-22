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
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Fiscal\Domain\ValueObject\TaxFamily;
use App\Fiscal\Domain\ValueObject\TaxKind;
use App\ImportExport\Application\DeclaresImport;
use App\ImportExport\Application\ImportCells;
use App\ImportExport\Application\ImportChoice;
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
use App\Module\Products\Application\Cost\PurchaseCost;
use App\Module\Products\Application\CustomerPrice;
use App\Module\Products\Application\GrossUnreachable;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Application\ProductBarcodeTaken;
use App\Module\Products\Application\ProductCheckInput;
use App\Module\Products\Application\ProductInput;
use App\Module\Products\Application\ProductReferenceTaken;
use App\Module\Products\Application\Stock\ProductFileStock;
use App\Module\Products\Application\Stock\ProductHomes;
use App\Module\Products\Application\Stock\ProductReorderPoints;
use App\Module\Products\Application\Stock\ProductStockRefused;
use App\Module\Products\Application\Stock\ReorderPointRefused;
use App\Module\Products\Domain\Exception\InvalidProduct;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Repository\ProductCategoryRepository;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Module\Products\Domain\ValueObject\Barcode;
use App\Module\Products\Domain\ValueObject\BarcodeLine;
use App\Module\Products\Domain\ValueObject\BarcodeRole;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Products\Domain\ValueObject\ProductTracking;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\Repository\VendorRepository;
use App\ModuleRegistry\Application\ModuleStates;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What a product file holds, for one company, and what one of its rows does.
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
    /** Gives a new product the row names no tax for the company's default line taxes, as the product form does. */
    public const string DEFAULT_TAXES = 'default_taxes';
    /** Creates a new product the row gives no sale price at 0 and switched off, which no document then sells. */
    public const string UNPRICED_INACTIVE = 'unpriced_inactive';
    /**
     * « Prix TTC impossible à atteindre »: where no net price at four decimals comes to the price written taxes included,
     * the row takes the nearest under it (the default), the nearest over it, or is refused, and says which it reached.
     */
    public const string UNREACHABLE_GROSS = 'unreachable_gross';
    public const string GROSS_BELOW = 'below';
    public const string GROSS_ABOVE = 'above';
    public const string GROSS_REFUSE = 'refuse';
    /** The unit a new product the row gives none is sold in, as the product form offers it. */
    private const string DEFAULT_UNIT = 'article.default_unit';

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
        'vendorIds' => ['supplier_number', 'invalid_value'],
        'checkNote' => ['to_check', 'invalid_text'],
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
        private ExcludedTaxFamilies $excluded,
        private ReadSetting $settings,
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
            [
                new ImportSwitch(self::DEFAULT_TAXES, 'import.products.default_taxes_switch', 'import.products.default_taxes_switch_note'),
                new ImportSwitch(self::UNPRICED_INACTIVE, 'import.products.unpriced_inactive', 'import.products.unpriced_inactive_note'),
                new ImportSwitch(self::UNREACHABLE_GROSS, 'import.products.unreachable_gross', 'import.products.unreachable_gross_note', [
                    new ImportChoice(self::GROSS_BELOW, 'import.products.unreachable_gross_below'),
                    new ImportChoice(self::GROSS_ABOVE, 'import.products.unreachable_gross_above'),
                    new ImportChoice(self::GROSS_REFUSE, 'import.products.unreachable_gross_refuse'),
                ]),
                ...$stock ? [
                    new ImportSwitch(self::IGNORE_QUANTITIES, 'import.stock.ignore_quantities', 'import.stock.ignore_quantities_note'),
                    new ImportSwitch(self::RECOUNT, 'import.stock.recount', 'import.stock.recount_note'),
                ] : [],
            ],
        );
    }

    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId, RowNotes $notes, ImportContext $context): RowImported
    {
        // Found again by the reference written in the row, else by the unit code written in it, never by its name.
        $reference = $record->value('reference');
        $existing = null === $reference ? $this->ofUnitCode($company, $record->value('barcode')) : $this->products->ofReferenceInCompany($reference, $company->getId());
        if (null !== $existing && ImportMode::Create === $mode) {
            throw null === $reference ? new RowRejected('barcode', 'A product already answers to this code. Import in "create and update" mode to update it.', 'already_exists', ['reference' => $existing->getReference()]) : new RowRejected('reference', 'A product already has this reference. Import in "create and update" mode to update it.', 'already_exists');
        }
        // What a product merged away was stays as merged, for the documents that still name it.
        if (null !== $existing && $existing->isMergedAway()) {
            [$merged, $kept] = [$existing->getReference(), $existing->standingFor()->getReference()];
            throw new RowRejected('reference', \sprintf('The product %s was merged into %s: change %s instead.', $merged, $kept, $kept), 'product_merged', ['reference' => $merged, 'kept' => $kept]);
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
        $reorderPoint = $faults->read(fn (): ?array => $this->reorderPointOf($company, $record, $home, $context));
        $quantity = $faults->read(fn (): ?array => $this->quantityOf($record, $context));

        $seesCosts = $context->once('sees-costs', fn (): bool => $this->guard->may($company, ProductPermission::COST_READ));
        [$codes, $vendor] = $this->codes($company, $record, $existing, $seesCosts, $faults, $notes);

        $written = null;
        try {
            $input = $this->input($company, $record, $existing, $faults, array_column($codes, 0), $vendor, $seesCosts, $context, $notes);
            $faults->stopIfAny();
            \assert(false !== $home);
            // Rendered before the write, which changes the product in place.
            $before = null === $existing ? [] : $this->inFile($company, $existing, $context, $record);
            if (null === $existing) {
                $written = $this->manage->create($company, $input, $actorUserId);
                $context->created('product', $written->getId());
                $this->settleHome($company, $written, $home, $actorUserId);
                $this->settleReorderPoint($company, $written, $reorderPoint, $actorUserId);
                if (null === $reference) {
                    $notes->note('reference', 'reference_given', ['reference' => $written->getReference()]);
                }
                // Created as the file says, without a tax, which only the preview can show before every document sells
                // it so: a file chooses no rate on its own.
                if ([] === $written->getDefaultTaxComponentIds()) {
                    $notes->note('default_tax_codes', 'no_taxes');
                } elseif ($this->givesDefaultTaxes($record, null, $context)) {
                    $notes->note('default_tax_codes', 'default_taxes_given', ['codes' => implode(' ', array_map(static fn (TaxComponent $tax): string => $tax->getCode(), $this->defaultTaxes($company, $context)))]);
                }
                if ($this->unpriced($record, null, $context)) {
                    $notes->note('unit_price_net', 'price_to_enter');
                }
                if (null === $record->value('unit_code')) {
                    $notes->note('unit_code', 'unit_to_confirm', ['code' => $written->getUnit()->getCode()]);
                }
                $this->noteBelowCost($written, $record, $seesCosts, $notes);
                $this->settleStock($company, $written, $record, $quantity, $home, $actorUserId, $context, $notes);

                return RowImported::Created;
            }
            $written = $this->manage->revise($company, $existing->getId(), $input, $actorUserId);
            $this->noteBelowCost($written, $record, $seesCosts, $notes);
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
            throw new RowRejected('reorder_point', $refused->getMessage(), $refused->reason ?? 'invalid_quantity', $refused->params);
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
     * Where the row says this product normally lives, as a location the company has. A blank
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
        if (null === $code || '' === TypedText::trimmed($code)) {
            return null;
        }
        $found = $this->homes->locationsCoded($company, TypedText::trimmed($code));
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
     * inventory answers for.
     *
     * @return array<string, string> by column
     */
    private function inFile(Company $company, Product $product, ImportContext $context, ImportRecord $record): array
    {
        $codes = $context->once('tax-codes', function () use ($company): array {
            $codes = [];
            foreach ($this->taxes->ofCompany($company->getId()) as $tax) {
                $codes[$tax->getId()->toRfc4122()] = $tax->getCode();
            }

            return $codes;
        });
        $cells = [];
        foreach (array_diff($context->columns, self::STOCK_COLUMNS) as $column) {
            $cells[$column] = ProductInFile::cell($product, $column, $codes, $record->value('pack_barcode'), $record->value('supplier_code'), $record->value('supplier_number'));
        }

        return $cells;
    }

    /**
     * The reorder point the row sets, and the establishment it is kept for: the
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
    private function reorderPointOf(Company $company, ImportRecord $record, Uuid|false|null $homeLocationId, ImportContext $context): ?array
    {
        $quantity = ImportCells::decimal($record, 'reorder_point', 3);
        if (null === $quantity || '' === TypedText::trimmed($quantity)) {
            return null;
        }
        $quantity = TypedText::trimmed($quantity);
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/', $quantity)) {
            throw new RowRejected('reorder_point', 'A reorder point is a quantity from 0, with at most three decimals.', 'invalid_quantity');
        }
        if (false === $homeLocationId) {
            return null;
        }
        $establishmentId = null === $homeLocationId ? null : $this->reorderPoints->establishmentOfLocation($company, $homeLocationId);
        if (null === $establishmentId) {
            $establishments = $context->once('establishments', fn (): array => $this->establishments->ofCompany($company->getId()));
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
    private function input(Company $company, ImportRecord $record, ?Product $current, RowFaults $faults, array $codes, ?Uuid $vendor, bool $seesCosts, ImportContext $context, RowNotes $notes): ProductInput
    {
        $held = $current?->getDetails();
        $kind = $record->value('kind');
        $taxIds = $faults->read(fn (): array => $this->taxIds($company, $record, $current, $context), false);
        $unpriced = $this->unpriced($record, $current, $context);
        $price = $unpriced ? '0' : $faults->read(fn (): string => $this->priceOf($company, $record, $held, $taxIds, $context, $notes), '0');
        $productKind = null === $kind ? ($held->kind ?? ProductKind::Goods) : $faults->read(static fn (): ProductKind => ProductKind::tryFrom(self::KINDS[mb_strtolower(TypedText::trimmed($kind))] ?? '') ?? throw new RowRejected('kind', 'A product is "goods" or a "service".', 'not_one_of', ['choices' => implode(', ', array_column(ProductKind::cases(), 'value'))]), ProductKind::Goods);

        return new ProductInput(
            // Left out, a new product is given the next of the company's format and a product found by its code keeps its own.
            $record->value('reference') ?? $current?->getReference() ?? '',
            new ProductDetails(
                $record->value('name') ?? $held->name ?? '',
                $record->value('description') ?? $held?->description,
                $productKind,
                $price,
                ($seesCosts ? $faults->read(fn (): ?string => $this->costOf($company, $record, $taxIds)) : null) ?? $held?->costPrice,
                $record->value('substitution_group') ?? $held?->substitutionGroup,
            ),
            $faults->read(fn (): Uuid => $this->unitId($company, $record, $current, $context), Uuid::v7()),
            $faults->read(fn (): ?Uuid => $this->categoryId($company, $record, $current, $context), $current?->getCategory()?->getId()),
            false === $taxIds ? [] : $taxIds,
            $unpriced ? false : $faults->read(static fn (): ?bool => self::yesNo($record, 'active')) ?? $current?->isActive() ?? true,
            $this->customValues($company, $record, $current, $faults, $context),
            $codes,
            // Left blank, the product keeps its own; a new one follows what its category or company says.
            $faults->read(static fn (): ?ProductTracking => self::tracking($record)),
            // Somebody who sees no cost is shown no supplier's code, so the product form's rule keeps them as stored.
            seesCosts: $seesCosts,
            // The vendor the row names is added to those the product is bought from; a file removes none.
            vendorIds: null === $vendor ? null : self::withVendor($current, $vendor),
            check: $faults->read(static fn (): ?ProductCheckInput => self::check($record, $current)),
            // A unit the row does not name is not one confirmed, on a new product as on one found again.
            unitNamed: null !== $record->value('unit_code'),
            // Goods coming in from a file are counted on the shelf before their stock is trusted.
            awaitsCount: null === $current && ProductKind::Goods === $productKind,
        );
    }

    /**
     * The net price the row sets: written as it is, or from the price taxes included a counter thinks in (F26), never
     * both, as the two could disagree. A price taxes included is counted over the row's taxes, else the product's;
     * taxes the row names wrongly are refused on their own cell and leave it unjudged. One no net price reaches is
     * taken at the nearest under it, or over it, or refused, as « Prix TTC impossible à atteindre » says.
     *
     * @param list<Uuid>|false $taxIds false when the row's taxes were refused
     *
     * @throws RowRejected
     */
    private function priceOf(Company $company, ImportRecord $record, ?ProductDetails $held, array|false $taxIds, ImportContext $context, RowNotes $notes): string
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
            $reached = match ($context->chosen(self::UNREACHABLE_GROSS)) {
                self::GROSS_ABOVE => $unreachable->above,
                self::GROSS_REFUSE => throw new RowRejected('unit_price_gross', $unreachable->getMessage(), 'gross_unreachable', ['gross' => $gross, 'below' => $unreachable->below, 'above' => $unreachable->above]),
                default => $unreachable->below,
            };
            $notes->note('unit_price_gross', 'gross_reached', ['gross' => $gross, 'reached' => $reached]);

            // The nearest prices are those a net price reaches, so this one is found.
            return $this->prices->netOf($company, array_map(static fn (Uuid $id): string => $id->toRfc4122(), $taxIds), $reached);
        }
    }

    /**
     * « À vérifier » as the row writes it: its note sets the mark with that note; a blank cell keeps the mark as it is,
     * and a price of nothing meant stays as the product holds it.
     *
     * @throws RowRejected
     */
    private static function check(ImportRecord $record, ?Product $current): ?ProductCheckInput
    {
        $mark = ImportCells::checkMark($record, 'to_check');
        if (null === $mark) {
            return null;
        }

        return new ProductCheckInput($mark, $current?->isZeroPriceIntended() ?? false);
    }

    /** @throws RowRejected */
    private static function tracking(ImportRecord $record): ?ProductTracking
    {
        $cell = $record->value('tracking');
        if (null === $cell) {
            return null;
        }

        return ProductTracking::tryFrom(self::TRACKINGS[mb_strtolower(TypedText::trimmed($cell))] ?? '')
            ?? throw new RowRejected('tracking', 'Stock is told apart by "none", "lot" or "serial".', 'not_one_of', ['choices' => implode(', ', array_column(ProductTracking::cases(), 'value'))]);
    }

    /**
     * The codes the product is written with: the unit code the row gives
     * replaces the unit row and comes first; the pack and the supplier's code it gives are added, or update the one the
     * product holds under that code; nothing the row does not name is removed. A supplier's code is only for somebody
     * who sees costs: for anybody else the product's are left out here and kept as stored by the use case.
     *
     * The supplier the row names by number is returned beside them: the product is bought from it, code or not.
     *
     * @return array{list<array{BarcodeInput, array<string, string>}>, Uuid|null} each code, with the column each of its
     *                                                                            fields came from, and the supplier named
     */
    private function codes(Company $company, ImportRecord $record, ?Product $current, bool $seesCosts, RowFaults $faults, RowNotes $notes): array
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
        $supplier = null;
        if (null !== $number || null !== $code) {
            $supplier = $faults->read(fn (): Uuid => $this->supplierId($company, $number, $code, $notes));
            if (null !== $supplier && null !== $code) {
                $codes = self::placed($codes, self::heldAt($codes, BarcodeRole::Supplier, $code), [new BarcodeInput(BarcodeRole::Supplier->value, $code, 1, $supplier), self::cellsOf('supplier_code', ['supplierId' => 'supplier_number'])]);
            }
        }

        return [$codes, $supplier];
    }

    /**
     * The vendors the product is bought from, with this one among them.
     *
     * @return list<Uuid>
     */
    private static function withVendor(?Product $current, Uuid $vendor): array
    {
        $ids = array_map(static fn (Vendor $held): Uuid => $held->getId(), $current?->getVendors() ?? []);
        foreach ($ids as $id) {
            if ($id->equals($vendor)) {
                return $ids;
            }
        }

        return [...$ids, $vendor];
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
     * The supplier the row names by number, alone or with its code: a code names no supplier on its own.
     *
     * @throws RowRejected
     */
    private function supplierId(Company $company, ?string $number, ?string $code, RowNotes $notes): Uuid
    {
        if (null === $number) {
            throw new RowRejected('supplier_number', 'A supplier\'s code goes with the supplier\'s number.', 'value_required');
        }
        $named = $this->vendors->ofNumberInCompany($number, $company->getId())
            ?? throw new RowRejected('supplier_number', \sprintf('The company has no supplier numbered "%s".', $number), 'unknown_vendor', ['number' => $number]);

        return MergedVendors::followed($named, 'supplier_number', $notes)->getId();
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
    private function unitId(Company $company, ImportRecord $record, ?Product $current, ImportContext $context): Uuid
    {
        $code = $record->value('unit_code');
        if (null !== $current && null === $code) {
            return $current->getUnit()->getId();
        }
        // A new product the row gives no unit is sold in the company's default one, and asks for it to be confirmed.
        $code ??= $this->defaultUnit($company);

        return $context->once('unit:'.$code, fn (): ?Uuid => $this->units->ofCodeInCompany($code, $company->getId())?->getId())
            ?? throw new RowRejected('unit_code', \sprintf('The company has no unit coded "%s".', $code), 'unknown_unit', ['code' => $code]);
    }

    private function defaultUnit(Company $company): string
    {
        return $this->settings->text(new SettingContext($company), self::DEFAULT_UNIT);
    }

    private function categoryId(Company $company, ImportRecord $record, ?Product $current, ImportContext $context): ?Uuid
    {
        $name = $record->value('category');
        if (null === $name) {
            return $current?->getCategory()?->getId();
        }

        return $context->once('category:'.$name, fn (): ?Uuid => $this->categories->ofNameInCompany($name, $company->getId())?->getId())
            ?? throw new RowRejected('category', \sprintf('The company has no product category named "%s". Create it first.', $name), 'unknown_category', ['name' => $name]);
    }

    /**
     * The cost the row sets: written as it is, or from the cost taxes included as the vendor invoiced it, never both.
     * Taxes included, it is the figure less the VAT at the product's rate, which a liable company gets back, by the one
     * purchase cost rule (`PurchaseCost`). A company whose regime charges no VAT gets none back, so its cost is the
     * figure as it stands (docs/fiscal/TN.md § 9a).
     *
     * @param list<Uuid>|false $taxIds false when the row's taxes were refused
     *
     * @throws RowRejected
     */
    private function costOf(Company $company, ImportRecord $record, array|false $taxIds): ?string
    {
        $net = ImportCells::decimal($record, 'cost_price', 4);
        if (null === $record->value('cost_price_gross')) {
            return $net;
        }
        if (null !== $net) {
            throw new RowRejected('cost_price_gross', 'A row gives its cost without taxes or with them, not both.', 'cost_net_and_gross');
        }
        $gross = (string) ImportCells::decimal($record, 'cost_price_gross', 4);
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,9})(\.[0-9]+)?$/', $gross)) {
            throw new RowRejected('cost_price_gross', 'A cost is a number from 0.', 'invalid_price');
        }
        if (\in_array(TaxFamily::Vat, $this->excluded->companyRegime($company)->excludedFamilies ?? [], true)) {
            return PurchaseCost::fromGross(Decimal::of($gross), false, Decimal::zero());
        }
        if (false === $taxIds) {
            return null;
        }
        $rate = Decimal::zero();
        foreach ($taxIds as $id) {
            $tax = $this->taxes->ofIdInCompany($id, $company->getId());
            if (null !== $tax && TaxFamily::Vat === $tax->getFamily() && TaxKind::PercentageLine === $tax->getKind() && null !== $tax->getRate()) {
                $rate += Decimal::of($tax->getRate());
            }
        }
        if (0 === $rate->compare(0)) {
            throw new RowRejected('cost_price_gross', 'A cost taxes included needs the VAT rate it carries: name the product\'s taxes.', 'cost_gross_without_vat');
        }

        return PurchaseCost::fromGross(Decimal::of($gross), true, $rate);
    }

    /**
     * The taxes the company marks as its default on a line, the ones a new product starts with in its form.
     *
     * @return list<TaxComponent>
     */
    private function defaultTaxes(Company $company, ImportContext $context): array
    {
        return $context->once('default-taxes', fn (): array => array_values(array_filter(
            $this->taxes->ofCompany($company->getId()),
            static fn (TaxComponent $tax): bool => $tax->isActive() && $tax->isDefault() && TaxKind::PercentageLine === $tax->getKind(),
        )));
    }

    /** A new product the row names no tax for, while the person ticked « Donner les taxes par défaut ». */
    private function givesDefaultTaxes(ImportRecord $record, ?Product $current, ImportContext $context): bool
    {
        return null === $current && null === $record->value('default_tax_codes') && $context->ticked(self::DEFAULT_TAXES);
    }

    /**
     * A new product the row gives no sale price for, while the person ticked « Importer désactivés les produits sans
     * prix », unless the row asks it active: that one is refused for its price as before, not switched off unasked.
     */
    private function unpriced(ImportRecord $record, ?Product $current, ImportContext $context): bool
    {
        $active = $record->value('active');

        return null === $current
            && null === $record->value('unit_price_net') && null === $record->value('unit_price_gross')
            && $context->ticked(self::UNPRICED_INACTIVE)
            && (null === $active || !\in_array(mb_strtolower(TypedText::trimmed($active)), self::YES, true));
    }

    /** A product the row leaves sold under its cost, most often a slip in the sheet, is pointed out. */
    private function noteBelowCost(Product $written, ImportRecord $record, bool $seesCosts, RowNotes $notes): void
    {
        $details = $written->getDetails();
        $touched = null !== $record->value('unit_price_net') || null !== $record->value('unit_price_gross') || null !== $record->value('cost_price') || null !== $record->value('cost_price_gross');
        if ($seesCosts && $touched && null !== $details->costPrice && 0 > Decimal::of($details->unitPriceNet)->compare(Decimal::of($details->costPrice))) {
            $notes->note('unit_price_net', 'below_cost', ['cost' => $details->costPrice]);
        }
    }

    /** @return list<Uuid> */
    private function taxIds(Company $company, ImportRecord $record, ?Product $current, ImportContext $context): array
    {
        $codes = $record->value('default_tax_codes');
        if ($this->givesDefaultTaxes($record, $current, $context)) {
            return array_map(static fn (TaxComponent $tax): Uuid => $tax->getId(), $this->defaultTaxes($company, $context));
        }
        if (null === $codes) {
            return array_map(Uuid::fromString(...), $current?->getDefaultTaxComponentIds() ?? []);
        }

        $ids = [];
        foreach (preg_split('/[\s;,]+/', $codes, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
            $ids[] = $context->once('tax:'.$code, fn (): ?Uuid => $this->taxes->ofCodeInCompany($code, $company->getId())?->getId())
                ?? throw new RowRejected('default_tax_codes', \sprintf('The company has no tax coded "%s".', $code), 'unknown_tax_code', ['code' => $code]);
        }

        return $ids;
    }

    /**
     * The row's custom field cells, typed as the form sends them, over the values held.
     *
     * @return array<string, string|int|float|bool>
     */
    private function customValues(Company $company, ImportRecord $record, ?Product $current, RowFaults $faults, ImportContext $context): array
    {
        $values = $current?->getCustomFields() ?? [];
        foreach ($context->once('custom-fields', fn (): array => $this->customFields->ofCompanyAndEntity($company->getId(), CustomFieldEntity::Product)) as $field) {
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
     * (product.cost.read): a file from anyone else naming it is refused for the
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
                ? [
                    new ImportColumn('cost_price', 'import.products.cost_price', false, '0.2200', 'import.products.cost_note'),
                    // No example: the template's row would then give the cost twice.
                    new ImportColumn('cost_price_gross', 'import.products.cost_price_gross', false, null, 'import.products.cost_price_gross_note'),
                ]
                : [],
            new ImportColumn('barcode', 'import.products.barcode', false, '6191234567897', 'import.products.barcode_note', code: true),
            new ImportColumn('pack_barcode', 'import.products.pack_barcode', false, '16191234567894', 'import.products.pack_barcode_note', code: true),
            new ImportColumn('pack_quantity', 'import.products.pack_quantity', false, '12', 'import.products.pack_quantity_note'),
            // Who a product is bought from is read with the product; a supplier's own code for it, with costs.
            ...$this->modules->isEnabled($company->getId(), 'vendors')
                ? [
                    new ImportColumn('supplier_number', 'import.products.supplier_number', false, 'FRN-0001', 'import.products.supplier_number_note', code: true),
                    ...$this->guard->may($company, ProductPermission::COST_READ)
                        ? [new ImportColumn('supplier_code', 'import.products.supplier_code', false, 'SF-883', 'import.products.supplier_code_note', code: true)]
                        : [],
                ]
                : [],
            new ImportColumn('default_tax_codes', 'import.products.default_taxes', false, null, 'import.products.default_taxes_note'),
            new ImportColumn('active', 'import.products.active', false, 'yes', 'import.boolean_note'),
            new ImportColumn('to_check', 'import.products.to_check', false, null, 'import.products.to_check_note'),
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

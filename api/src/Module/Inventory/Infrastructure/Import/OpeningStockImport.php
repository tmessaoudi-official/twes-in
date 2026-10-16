<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Import;

use App\ImportExport\Application\DeclaresImport;
use App\ImportExport\Application\ImportCells;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportContext;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\ImportRecord;
use App\ImportExport\Application\ImportSubject;
use App\ImportExport\Application\ImportSwitch;
use App\ImportExport\Application\RowFaults;
use App\ImportExport\Application\RowIdentity;
use App\ImportExport\Application\RowImported;
use App\ImportExport\Application\RowNotes;
use App\ImportExport\Application\RowRejected;
use App\Module\Inventory\Application\ImportStock;
use App\Module\Inventory\Application\StockChange;
use App\Module\Inventory\Application\StockImportRefused;
use App\Module\Inventory\Domain\Model\StockLocation;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Module\Products\Domain\ValueObject\Barcode;
use App\Module\Products\Domain\ValueObject\BarcodeRole;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Tenancy\Domain\Company;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The stock a company already has, from a file (docs/SPEC.md § 8 row 59, § 7 2026-10-09 10:40 (6)).
 *
 * A row names a product, by its reference or its unit code, and either ADDS goods (`stock_add`, a receipt at the
 * row's `unit_cost`, else the product's cost) or COUNTS them (`stock_count`, what is there, so the same file imported
 * twice leaves the same stock rather than twice as much). Both are written through KeepStock, the use cases of the
 * stock screen, lock and movement included, each movement marked as the import's. The place is the row's location
 * code, else the only place the product can mean (ImportStock::placeOf).
 *
 * A row is found again by its product AND the place it sits in: a product sits in as many places as it has stock, so
 * only the same pair twice in one file is a duplicate, however each row names it.
 */
final readonly class OpeningStockImport implements DeclaresImport
{
    public const string KEY = 'opening-stock';
    /** Counts again a place where goods came in or went out since its last count, which a count would otherwise erase. */
    public const string RECOUNT = 'recount';

    public function __construct(
        private ImportStock $stock,
        private ProductRepository $products,
        private EntityManagerInterface $entityManager,
        private CompanyGuard $guard,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function permission(): string
    {
        return StockPermission::WRITE;
    }

    public function module(): string
    {
        return InventoryModule::KEY;
    }

    public function identityColumns(): array
    {
        return ['reference', 'location_code'];
    }

    /** The product and the place the row resolves to; null when it names none, which import() then refuses. */
    public function identityOf(Company $company, ImportRecord $record): ?RowIdentity
    {
        try {
            $product = $this->productOf($company, $record);
            $place = $this->stock->placeOf($company, $product, $record->value('location_code'));
        } catch (RowRejected|StockImportRefused) {
            return null;
        }

        return new RowIdentity(null === $record->value('reference') ? 'barcode' : 'reference', $product->getId()->toRfc4122()."\x1f".$place->getId()->toRfc4122(), 'product and location');
    }

    /**
     * `unit_cost` is offered only to someone who may read costs, as the product file's `cost_price` is: a file from
     * anyone else naming it is refused for the column, and the receipt takes the product's own cost.
     */
    public function subjectFor(Company $company): ImportSubject
    {
        return new ImportSubject(self::KEY, [
            new ImportColumn('reference', 'import.opening_stock.reference', false, 'VIS-6X40', 'import.opening_stock.reference_or_barcode_note', code: true),
            new ImportColumn('barcode', 'import.opening_stock.barcode', false, '6191234567897', 'import.opening_stock.barcode_note', code: true),
            new ImportColumn('location_code', 'import.opening_stock.location_code', false, 'A-12', 'import.opening_stock.location_optional_note', code: true),
            new ImportColumn('stock_add', 'import.stock.stock_add', false, '24', 'import.stock.stock_add_note'),
            new ImportColumn('stock_count', 'import.stock.stock_count', false, '120', 'import.stock.stock_count_note'),
            ...$this->guard->may($company, ProductPermission::COST_READ)
                ? [new ImportColumn('unit_cost', 'import.opening_stock.unit_cost', false, '0.2200', 'import.opening_stock.unit_cost_note')]
                : [],
        ], [new ImportSwitch(self::RECOUNT, 'import.stock.recount', 'import.stock.recount_note')]);
    }

    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId, RowNotes $notes, ImportContext $context): RowImported
    {
        // Every cell is read before anything is written, and every fault found is said at once (ruling 9). A refused
        // number stands as `false`, so the rules over the row's numbers are not broken for want of it; the place is
        // found through the product, so a product the company has not leaves it unread.
        $faults = new RowFaults();
        $product = $faults->read(fn (): Product => $this->productOf($company, $record));
        $productColumn = null === $record->value('reference') ? 'barcode' : 'reference';
        $add = $faults->read(static fn (): ?string => ImportCells::decimal($record, 'stock_add', 3), false);
        $count = $faults->read(static fn (): ?string => ImportCells::decimal($record, 'stock_count', 3), false);
        $cost = $faults->read(static fn (): ?string => ImportCells::decimal($record, 'unit_cost', 4), false);
        $quantityColumn = null === $add ? 'stock_count' : 'stock_add';
        if (false !== $add && false !== $count && false !== $cost) {
            $faults->read(static fn () => self::holdNumbers($add, $count, $cost));
        }

        $place = null;
        $change = null;
        try {
            if (null !== $product) {
                $faults->read(fn () => $this->refusedOn($productColumn, $quantityColumn, fn () => $this->stock->stocked($product)));
                $place = $faults->read(fn (): StockLocation => $this->refusedOn($productColumn, $quantityColumn, fn (): StockLocation => $this->stock->placeOf($company, $product, $record->value('location_code'))));
            }
            $faults->stopIfAny();
            \assert(null !== $product && null !== $place && false !== $add && false !== $count && false !== $cost);
            $held = $this->stock->held($product, $place);
            if ($held && ImportMode::Create === $mode) {
                throw null === $add ? new RowRejected($productColumn, 'This product already has stock recorded at this location. Import in "create and update" mode to count it again.', 'already_counted') : new RowRejected($productColumn, 'This product already has stock recorded at this location. Import in "create and update" mode to add to it.', 'already_stocked', ['location' => $place->getCode()]);
            }
            $change = null === $add
                ? $this->stock->count($company, $product, $place, (string) $count, $context->ticked(self::RECOUNT), $actorUserId, $context->runId)
                : $this->stock->add($company, $product, $place, $add, $cost ?? $product->getDetails()->costPrice, $actorUserId, $context->runId);
            $notes->note($quantityColumn, 'stock_change', ['location' => $change->place, 'before' => $change->before, 'after' => $change->after]);

            return $held ? RowImported::Updated : RowImported::Created;
        } catch (StockImportRefused $refused) {
            throw self::rejection($refused, $productColumn, $quantityColumn);
        } finally {
            if (null !== $product) {
                $this->forget($product, $place, $change);
            }
        }
    }

    /**
     * What the row's numbers must be together: an addition or a count, never both nor neither, and a cost only with
     * goods added.
     *
     * @throws RowRejected
     */
    private static function holdNumbers(?string $add, ?string $count, ?string $cost): void
    {
        if (null !== $add && null !== $count) {
            throw new RowRejected('stock_count', 'A row adds goods or counts them, not both: keep one of stock_add and stock_count.', 'stock_add_and_count');
        }
        if (null === $add && null === $count) {
            throw new RowRejected('stock_count', 'Every row of this file adds goods or counts them: fill in stock_add or stock_count.', 'quantity_required');
        }
        if (null !== $cost && null === $add) {
            throw new RowRejected('unit_cost', 'A cost goes with goods added: a count finds what is there and buys nothing.', 'cost_without_addition');
        }
    }

    /**
     * The stock's own refusal, said on the cell it is about.
     *
     * @template T
     *
     * @param callable(): T $asking
     *
     * @return T
     *
     * @throws RowRejected
     */
    private function refusedOn(string $productColumn, string $quantityColumn, callable $asking): mixed
    {
        try {
            return $asking();
        } catch (StockImportRefused $refused) {
            throw self::rejection($refused, $productColumn, $quantityColumn);
        }
    }

    private static function rejection(StockImportRefused $refused, string $productColumn, string $quantityColumn): RowRejected
    {
        return new RowRejected(match ($refused->about) {
            StockImportRefused::PLACE => 'location_code', StockImportRefused::QUANTITY => $quantityColumn, StockImportRefused::COST => 'unit_cost', default => $productColumn,
        }, $refused->getMessage(), $refused->reason, $refused->params);
    }

    public function finished(Company $company, ImportContext $context, ?Uuid $actorUserId): void
    {
        $this->stock->finished($company, $context->runId, $actorUserId);
    }

    /**
     * The product the row names: by its reference, else by its unit code, the code a scanner reads on the shelf. A row
     * naming both must name ONE product, or one of its two cells is wrong and the file cannot say which.
     *
     * @throws RowRejected
     */
    private function productOf(Company $company, ImportRecord $record): Product
    {
        $reference = $record->value('reference');
        $code = $record->value('barcode');
        $byCode = null;
        if (null !== $code) {
            $held = $this->products->barcodeOfKeyInCompany(Barcode::keyOf($code), $company->getId());
            $byCode = null !== $held && BarcodeRole::Unit === $held->getRole() ? $held->getProduct() : null;
            // Read for its product alone: left managed, it would point at the product the row detaches once written.
            if (null !== $held) {
                $this->entityManager->detach($held);
            }
        }
        if (null === $reference) {
            if (null === $code) {
                throw new RowRejected('reference', 'Stock is kept for a product, so every row names one by its reference or its unit code.', 'value_required');
            }

            return $byCode ?? throw new RowRejected('barcode', \sprintf('No product of the company has the unit code "%s".', $code), 'unknown_barcode', ['code' => $code]);
        }
        $product = $this->products->ofReferenceInCompany($reference, $company->getId())
            ?? throw new RowRejected('reference', \sprintf('The company has no product referenced "%s". Import the products first.', $reference), 'unknown_product', ['reference' => $reference]);
        if (null !== $code && (null === $byCode || !$byCode->getId()->equals($product->getId()))) {
            throw new RowRejected('barcode', \sprintf('The unit code "%s" is not the one of %s.', $code, $reference), 'barcode_not_the_reference', ['code' => $code, 'reference' => $reference]);
        }

        return $product;
    }

    /**
     * Doctrine's batch processing: every flush walks every managed entity, so what a row wrote leaves the unit of work
     * once written, or a file costs the square of its length. The product and the place are read again by the next row
     * through the same repositories.
     */
    private function forget(Product $product, ?StockLocation $place, ?StockChange $change): void
    {
        foreach ([$change?->movement, $product, $place] as $written) {
            if (null !== $written && $this->entityManager->contains($written)) {
                $this->entityManager->detach($written);
            }
        }
    }
}

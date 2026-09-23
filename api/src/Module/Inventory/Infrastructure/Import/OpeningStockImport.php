<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Import;

use App\ImportExport\Application\DeclaresImport;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\ImportRecord;
use App\ImportExport\Application\ImportSubject;
use App\ImportExport\Application\RowImported;
use App\ImportExport\Application\RowRejected;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Inventory\Infrastructure\ApiPlatform\StockPermission;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Domain\ProductTracking;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The stock a company already has, from a file (docs/SPEC.md § 8 row 59).
 *
 * A row is a COUNT, not a receipt: it says what is there, so the same file imported twice leaves the same stock rather
 * than twice as much. That is what makes an opening balance safe to re-run after a correction, and it is why this
 * writes through KeepStock::count — the use case the stock-count screen uses, lock and movement included.
 *
 * It is the first subject found again by a PAIR. A product sits in as many places as it has stock, so `reference`
 * alone names nothing: the identity is the product AND the location, and only the same pair twice in one file is a
 * duplicate. A location is named by its code, which is one place per ESTABLISHMENT — so a code two establishments
 * share is refused rather than guessed at, and the person is told to rename one or import per site.
 */
final readonly class OpeningStockImport implements DeclaresImport
{
    public const string KEY = 'opening-stock';

    /** The column each field a refusal names is read from, with the code the screen translates. */
    private const array REFUSAL_OF = [
        'productId' => ['reference', 'not_stocked'],
        'locationId' => ['location_code', 'invalid_location'],
        'quantity' => ['quantity', 'invalid_quantity'],
    ];

    public function __construct(
        private KeepStock $stock,
        private ProductRepository $products,
        private StockLocationRepository $locations,
        private StockMovementRepository $movements,
        private EntityManagerInterface $entityManager,
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

    public function subjectFor(Company $company): ImportSubject
    {
        return new ImportSubject(self::KEY, [
            new ImportColumn('reference', 'import.opening_stock.reference', true, 'VIS-6X40', 'import.opening_stock.reference_note'),
            new ImportColumn('location_code', 'import.opening_stock.location_code', true, 'A-12', 'import.opening_stock.location_code_note'),
            new ImportColumn('quantity', 'import.opening_stock.quantity', true, '120.000', 'import.opening_stock.quantity_note'),
        ]);
    }

    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId): RowImported
    {
        $reference = $record->value('reference') ?? throw new RowRejected('reference', 'Stock is counted for a product, so every row names one by its reference.', 'value_required');
        $code = $record->value('location_code') ?? throw new RowRejected('location_code', 'Stock sits somewhere, so every row names the location it was counted in.', 'value_required');
        $quantity = $record->value('quantity') ?? throw new RowRejected('quantity', 'A count says how many were found, so every row needs a quantity.', 'value_required');
        // A French or Tunisian spreadsheet writes a decimal with a comma; the domain reads a point, as the form sends it.
        $quantity = str_replace(',', '.', $quantity);

        $product = $this->products->ofReferenceInCompany($reference, $company->getId())
            ?? throw new RowRejected('reference', \sprintf('The company has no product referenced "%s". Import the products first.', $reference), 'unknown_product', ['reference' => $reference]);
        $location = $this->locationOf($company, $code);
        // A file names no lot yet, and a tracked product's stock is always some lot's: its opening stock is counted on
        // the stock screen, lot by lot, until the import reads lots too (docs/SPEC.md § 7, 2026-09-23).
        if (ProductTracking::None !== $product->getTracking()) {
            throw new RowRejected('reference', \sprintf('%s is tracked by lot or serial number: count its opening stock on the stock screen, lot by lot.', $reference), 'lot_tracked', ['reference' => $reference]);
        }

        $counted = 0 !== $this->movements->countOf($product->getId(), $location->getId());
        if ($counted && ImportMode::Create === $mode) {
            throw new RowRejected('reference', 'This product already has stock recorded at this location. Import in "create and update" mode to count it again.', 'already_counted');
        }

        $movement = null;
        try {
            $movement = $this->stock->count($company, $product->getId(), $location->getId(), $quantity, $actorUserId);

            return $counted ? RowImported::Updated : RowImported::Created;
        } catch (InvalidStockMovement $refused) {
            [$column, $reason] = self::REFUSAL_OF[$refused->field] ?? ['reference', 'invalid_value'];

            throw new RowRejected($column, $refused->getMessage(), $reason);
        } finally {
            // Doctrine's batch processing: every flush walks every managed entity, so what a row wrote leaves the unit
            // of work once written, or a file costs the square of its length. The product and the location are read
            // again by the next row through the same repositories.
            foreach ([$movement, $product, $location] as $written) {
                if (null !== $written) {
                    $this->entityManager->detach($written);
                }
            }
        }
    }

    /**
     * The one location of this company under that code. A code is unique per establishment, never per company, so two
     * sites may legitimately both have an "A-12": a file naming the code alone cannot say which, and guessing would
     * put the goods in the wrong building.
     *
     * @throws RowRejected
     */
    private function locationOf(Company $company, string $code): StockLocation
    {
        $found = $this->locations->ofCodeInCompany($code, $company->getId());
        if (1 === \count($found)) {
            return $found[0];
        }
        if ([] === $found) {
            throw new RowRejected('location_code', \sprintf('The company has no stock location coded "%s". Create it first, under Stock.', $code), 'unknown_location', ['code' => $code]);
        }

        throw new RowRejected('location_code', \sprintf('Several establishments have a location coded "%s", so this file cannot say which one. Rename one of them, or import one establishment at a time.', $code), 'ambiguous_location', ['code' => $code]);
    }
}

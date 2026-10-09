<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Module\Inventory\Domain\ProductHomeLocationRepository;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductTracking;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use BcMath\Number;
use Symfony\Component\Uid\Uuid;

/**
 * The quantities a file brings, row by row, for every file that carries them — the opening stock and the products
 * (docs/SPEC.md § 7, 2026-10-09 09:56 and 10:40 (6)). A row ADDS goods (a receipt, at a cost, so the valuation holds)
 * or COUNTS them (what is there, so the same file twice leaves the same stock), at the place it names or, failing
 * that, the one place it can only mean. Each movement carries the import's id, and raises its alerts once the file is
 * committed, all together.
 *
 * Runs inside the import's one transaction; a refusal names what it is about, never a column, since each file writes
 * its own.
 */
final readonly class ImportStock
{
    public function __construct(
        private KeepStock $stock,
        private StockMovementRepository $movements,
        private StockLocationRepository $locations,
        private ProductHomeLocationRepository $homes,
        private EstablishmentRepository $establishments,
        private ManageStockLocations $manageLocations,
        private RaiseStockAlerts $alerts,
        private TellStockKeepers $tell,
    ) {
    }

    /**
     * The product as a file may give it quantities: goods whose stock is kept, counted without lots.
     *
     * @throws StockImportRefused
     */
    public function stocked(Product $product): void
    {
        if (!$this->stock->tracked($product)) {
            throw new StockImportRefused(StockImportRefused::PRODUCT, 'not_stocked', \sprintf('No stock is kept of %s: only goods whose stock tracking is on are kept.', $product->getReference()), ['reference' => $product->getReference()]);
        }
        // A file names no lot yet, and a tracked product's stock is always some lot's: it is counted on the stock
        // screen, lot by lot, until a file reads lots too (docs/SPEC.md § 7, 2026-09-23).
        if (ProductTracking::None !== $product->getTracking()) {
            throw new StockImportRefused(StockImportRefused::PRODUCT, 'lot_tracked', \sprintf('%s is tracked by lot or serial number: count its stock on the stock screen, lot by lot.', $product->getReference()), ['reference' => $product->getReference()]);
        }
    }

    /**
     * Where a row's quantity goes: the place it names by code; else the home the row gives; else the product's own
     * home; else the default place of the company's only establishment. A company with several establishments and a
     * row naming none of them cannot say which building is meant, and guessing puts goods in the wrong one.
     *
     * @throws StockImportRefused
     */
    public function placeOf(Company $company, Product $product, ?string $code, ?Uuid $homeLocationId = null): StockLocation
    {
        if (null !== $code) {
            $found = $this->locations->ofCodeInCompany($code, $company->getId());
            if ([] === $found) {
                throw new StockImportRefused(StockImportRefused::PLACE, 'unknown_location', \sprintf('The company has no stock location coded "%s". Create it first, under Stock.', $code), ['code' => $code]);
            }
            if (1 !== \count($found)) {
                throw new StockImportRefused(StockImportRefused::PLACE, 'ambiguous_location', \sprintf('Several establishments have a location coded "%s", so this file cannot say which one. Rename one of them, or import one establishment at a time.', $code), ['code' => $code]);
            }

            return $found[0];
        }
        if (null !== $homeLocationId) {
            $home = $this->locations->ofIdInCompany($homeLocationId, $company->getId());
            if (null !== $home) {
                return $home;
            }
        }
        $mains = $this->homes->mainLocationIdsOf($product->getId(), $company->getId());
        $home = 1 === \count($mains) ? $this->locations->ofIdInCompany($mains[0], $company->getId()) : null;
        if (null !== $home) {
            return $home;
        }
        $establishments = $this->establishments->ofCompany($company->getId());
        if ([] === $mains && 1 === \count($establishments)) {
            return $this->manageLocations->defaultOf($establishments[0]);
        }

        throw new StockImportRefused(StockImportRefused::PLACE, 'location_needed', 'The company has several establishments, so a quantity needs the row to name its location.');
    }

    /**
     * Goods added where they are, at the cost given, else nothing typed (the receipt is then valued at the average).
     *
     * @throws StockImportRefused
     */
    public function add(Company $company, Product $product, StockLocation $place, string $quantity, ?string $unitCost, ?Uuid $actorUserId, Uuid $runId): StockChange
    {
        $before = $this->movements->onHand($product->getId(), $place->getId());
        $movement = $this->moving(fn (): StockMovement => $this->stock->receive($company, $product->getId(), $place->getId(), $quantity, $actorUserId, unitCost: $unitCost, importRunId: $runId));

        return self::change($product, $place, $movement, $before);
    }

    /**
     * What is there, replacing what the stock says. A count taken before goods came in or went out there would erase
     * them, so where any did since the last count the row is refused unless the person ticked « Recompter ».
     *
     * @throws StockImportRefused
     */
    public function count(Company $company, Product $product, StockLocation $place, string $counted, bool $recount, ?Uuid $actorUserId, Uuid $runId): StockChange
    {
        if (!$recount && $this->movements->movedSinceLastCount($product->getId(), $place->getId())) {
            throw new StockImportRefused(StockImportRefused::QUANTITY, 'moved_since_count', \sprintf('Goods came in or went out of %s at %s since it was last counted: tick « Recompter » to count it again.', $product->getReference(), $place->getCode()), ['location' => $place->getCode()]);
        }
        $before = $this->movements->onHand($product->getId(), $place->getId());
        $movement = $this->moving(fn (): StockMovement => $this->stock->count($company, $product->getId(), $place->getId(), $counted, $actorUserId, importRunId: $runId));

        return self::change($product, $place, $movement, $before);
    }

    /** Whether anything at all ever moved this product at this place: a row there is then not a new one. */
    public function held(Product $product, StockLocation $place): bool
    {
        return 0 !== $this->movements->countOf($product->getId(), $place->getId());
    }

    /**
     * Once the file is committed: the alerts its movements raise, once over all of them — a product that fell to its
     * reorder point is told once, whatever number of rows moved it — and one notification to the other stock keepers
     * for the differences its counts found, rather than one per row (docs/SPEC.md § 7, 2026-10-09 10:40 (10)).
     */
    public function finished(Company $company, Uuid $runId, ?Uuid $actorUserId): void
    {
        $moved = $this->movements->ofImportRun($runId, $company->getId());
        if ([] === $moved) {
            return;
        }
        $this->alerts->raiseFalls($moved);
        $counted = array_filter($moved, static fn (StockMovement $movement): bool => StockMovement::SOURCE_COUNT === $movement->getSourceType());
        $differences = \count(array_filter($counted, static fn (StockMovement $movement): bool => 0 !== new Number($movement->getQuantity())->compare(0)));
        if ($differences > 0) {
            $this->tell->imported($company->getId(), $actorUserId, ['counted' => \count($counted), 'differences' => $differences]);
        }
    }

    /**
     * Before and after in the unit's own decimals, as a person counts it: « 120 » screws, « 7.500 » kilos.
     *
     * @param numeric-string $before
     */
    private static function change(Product $product, StockLocation $place, StockMovement $movement, string $before): StockChange
    {
        $decimals = $product->getUnit()->getDecimals();

        return new StockChange($movement, $place->getCode(), new Number($before)->round($decimals)->value, new Number($before)->add($movement->getQuantity())->round($decimals)->value);
    }

    /**
     * @param callable(): StockMovement $write
     *
     * @throws StockImportRefused
     */
    private function moving(callable $write): StockMovement
    {
        try {
            return $write();
        } catch (InvalidStockMovement $refused) {
            [$about, $reason] = match ($refused->field) {
                'quantity' => [StockImportRefused::QUANTITY, 'invalid_quantity'],
                'unitCost' => [StockImportRefused::COST, 'invalid_price'],
                'locationId' => [StockImportRefused::PLACE, 'invalid_location'],
                'productId' => [StockImportRefused::PRODUCT, 'not_stocked'],
                default => [StockImportRefused::PRODUCT, 'invalid_value'],
            };

            throw new StockImportRefused($about, $reason, $refused->getMessage(), [], $refused);
        }
    }
}

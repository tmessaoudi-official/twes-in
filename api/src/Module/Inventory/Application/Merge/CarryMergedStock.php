<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\Merge;

use App\Module\Inventory\Application\RaiseStockAlerts;
use App\Module\Inventory\Domain\Model\StockLot;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Domain\Repository\StockLocationRepository;
use App\Module\Inventory\Domain\Repository\StockLotRepository;
use App\Module\Inventory\Domain\Repository\StockMovementRepository;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Module\Products\Domain\ValueObject\ProductTracking;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use BcMath\Number;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The stock of products merged into another (docs/SPEC.md § 7, the merge rulings): what each held, place by place and
 * lot by lot, goes to the product kept as a pair of movements, worth there what it was worth, and comes back the same
 * way when the merge is undone. Every lot of a product merged away has its twin under the product kept from then on,
 * by code and date, so goods coming back later for it find their lot. Runs inside the merge's transaction, whose lock
 * on the products' rows it shares; the products' stock it locks itself, as every writer of stock does.
 */
final readonly class CarryMergedStock
{
    public function __construct(
        private StockMovementRepository $movements,
        private StockLotRepository $lots,
        private StockLocationRepository $locations,
        private ProductRepository $products,
        private ClockInterface $clock,
        private LiveChanges $liveChanges,
        private ?RaiseStockAlerts $alerts = null,
    ) {
    }

    /**
     * @param non-empty-list<Uuid> $mergedIds
     * @param bool                 $readsCosts whether the person merging reads costs: goods carried move what the product kept is worth
     *
     * @throws StockNotCarried
     */
    public function carry(Uuid $companyId, Uuid $mergeId, Uuid $keptId, array $mergedIds, bool $readsCosts, Uuid $actorUserId): void
    {
        $kept = $this->product($keptId, $companyId);
        // A cost entered later shares its difference over what left the product since the receipt; goods carried in
        // and back by an undo would read as goods gone, so a receipt awaiting its cost holds the product kept too.
        if ($this->movements->awaitsCost($companyId, $kept->getId())) {
            throw new StockNotCarried('cost_pending', \sprintf('A receipt of %s waits for its cost: enter it before merging.', $kept->getReference()), ['reference' => $kept->getReference()]);
        }
        $arrived = [];
        foreach (self::sorted($mergedIds) as $mergedId) {
            $merged = $this->product($mergedId, $companyId);
            // A lot's lock before the stock's, as every writer of stock takes them.
            $twins = [];
            foreach ($this->lots->ofProduct($merged->getId()) as $lot) {
                $twins[$lot->getId()->toRfc4122()] = $this->twin($kept, $lot);
            }
            // Nothing of either moves until the merge ends: every movement's save takes this lock first.
            foreach (self::sorted([$kept->getId(), $merged->getId()]) as $locked) {
                $this->movements->lockValueOf($locked);
            }
            // A cost entered later corrects the receipt's own product, which a product merged away no longer moves.
            if ($this->movements->awaitsCost($companyId, $merged->getId())) {
                throw new StockNotCarried('cost_pending', \sprintf('A receipt of %s waits for its cost: enter it before merging.', $merged->getReference()), ['reference' => $merged->getReference()]);
            }
            $carried = [];
            foreach ($this->movements->levelsOf($companyId, $merged->getId()) as $level) {
                $quantity = is_numeric($level->quantity) ? $level->quantity : throw new \LogicException('A level\'s quantity is a number.');
                if (0 === new Number($quantity)->compare(0)) {
                    continue;
                }
                if (!$readsCosts) {
                    throw new StockNotCarried('costs_unseen', \sprintf('%s holds goods, which go to %s at what they are worth: only whoever reads costs merges them.', $merged->getReference(), $kept->getReference()));
                }
                $location = $this->locations->ofIdInCompany($level->locationId, $companyId)
                    ?? throw new \LogicException('A level is of one of the company\'s locations.');
                $lot = null === $level->lotId ? null : ($this->lots->ofIdInCompany($level->lotId, $companyId) ?? throw new \LogicException('A level is of one of the company\'s lots.'));
                // A lot opened for it while the merge waited for its stock has its twin found now.
                $twin = null === $lot ? null : ($twins[$lot->getId()->toRfc4122()] ??= $this->twin($kept, $lot));
                if (null !== $twin && ProductTracking::Serial === $kept->getTracking() && 1 === new Number($this->movements->onHandOfLot($twin->getId()))->add($quantity)->compare(1)) {
                    throw new StockNotCarried('serial_held', \sprintf('%s already holds the serial number %s.', $kept->getReference(), $twin->getCode()), ['code' => $twin->getCode()]);
                }
                $pair = StockMovement::carried($merged, $lot, $kept, $twin, $location, $quantity, self::sourceOf($mergeId, $merged), $actorUserId, $this->clock->now());
                array_push($carried, ...$pair);
                $arrived[] = $pair[1];
            }
            if ([] !== $carried) {
                $this->movements->save(...$carried);
                $this->liveChanges->stage(new LiveChange('stock', $merged->getId(), 'stock.merged', $actorUserId, $companyId));
            }
        }
        if ([] !== $arrived) {
            $this->liveChanges->stage(new LiveChange('stock', $kept->getId(), 'stock.merged', $actorUserId, $companyId));
            $this->alerts?->raiseFalls($arrived);
        }
    }

    /**
     * What the merge carried, carried back, worth what it arrived at: the product kept has moved nothing since, or the
     * undo was refused before this, so it gives back exactly what it took. The lots the merge opened stay, empty.
     *
     * @param non-empty-list<Uuid> $mergedIds
     */
    public function carryBack(Uuid $companyId, Uuid $mergeId, Uuid $keptId, array $mergedIds, ?Uuid $actorUserId): void
    {
        $kept = $this->product($keptId, $companyId);
        $returned = false;
        foreach (self::sorted($mergedIds) as $mergedId) {
            $merged = $this->product($mergedId, $companyId);
            $source = self::sourceOf($mergeId, $merged);
            $rows = $this->movements->ofSource(StockMovement::SOURCE_MERGE, $source, $companyId);
            $left = [];
            $arrived = [];
            foreach ($rows as $row) {
                $key = $row->getLocation()->getId()->toRfc4122().' '.($row->getLot()?->getCode() ?? '');
                if ($row->getProduct() === $merged) {
                    $left[$key] = $row;
                } elseif ($row->getProduct() === $kept) {
                    $arrived[$key] = $row;
                }
            }
            ksort($left);
            ksort($arrived);
            if (2 * \count($left) !== \count($rows) || array_keys($left) !== array_keys($arrived)) {
                throw new \LogicException(\sprintf('The goods merged from %s are not in pairs: they are not carried back.', $merged->getReference()));
            }
            $back = [];
            foreach ($arrived as $key => $row) {
                array_push($back, ...StockMovement::carried($kept, $row->getLot(), $merged, $left[$key]->getLot(), $row->getLocation(), $row->getQuantity(), $source, $actorUserId, $this->clock->now(), $row->getUnitCost()));
            }
            if ([] !== $back) {
                $this->movements->save(...$back);
                $this->liveChanges->stage(new LiveChange('stock', $merged->getId(), 'stock.merge_undone', $actorUserId, $companyId));
                $returned = true;
            }
        }
        if ($returned) {
            $this->liveChanges->stage(new LiveChange('stock', $kept->getId(), 'stock.merge_undone', $actorUserId, $companyId));
        }
    }

    /**
     * The lot of the product kept under the code of one merged into it: found, dated when it had no date, or opened.
     *
     * @throws StockNotCarried when it holds another date: one lot has one date
     */
    private function twin(Product $kept, StockLot $lot): StockLot
    {
        $this->lots->lock($kept->getId(), $lot->getCode());
        $twin = $this->lots->ofCode($kept->getId(), $lot->getCode());
        if (null === $twin) {
            $twin = StockLot::open($kept, $lot->getCode(), $lot->getExpiresOn(), $this->clock->now());
            $this->lots->save($twin);

            return $twin;
        }
        $date = $lot->getExpiresOn()?->format('Y-m-d');
        $twinDate = $twin->getExpiresOn()?->format('Y-m-d');
        if (null !== $date && null !== $twinDate && $date !== $twinDate) {
            throw new StockNotCarried('lot_dated_otherwise', \sprintf('The lot %s is used by %s on %s and by %s on %s: one lot has one date.', $lot->getCode(), $kept->getReference(), $twinDate, $lot->getProduct()->getReference(), $date), ['code' => $lot->getCode()]);
        }
        if ($twin->dated($lot->getExpiresOn())) {
            $this->lots->save($twin);
        }

        return $twin;
    }

    private function product(Uuid $id, Uuid $companyId): Product
    {
        return $this->products->ofIdInCompany($id, $companyId)
            ?? throw new \LogicException('The merge locked the products it merges, so each is there.');
    }

    /** One source per product merged away, so two carried to one place of the product kept are two rows. */
    private static function sourceOf(Uuid $mergeId, Product $merged): Uuid
    {
        return Uuid::v5($mergeId, $merged->getId()->toRfc4122());
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<Uuid> in one order, so two merges lock alike
     */
    private static function sorted(array $ids): array
    {
        usort($ids, static fn (Uuid $a, Uuid $b): int => strcmp($a->toRfc4122(), $b->toRfc4122()));

        return $ids;
    }
}

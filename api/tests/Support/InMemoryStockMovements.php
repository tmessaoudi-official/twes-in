<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Inventory\Domain\LotOnHand;
use App\Module\Inventory\Domain\RunningValue;
use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockLevelSearch;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Inventory\Domain\StockMovementSearch;
use App\Module\Inventory\Domain\StockValue;
use App\Module\Inventory\Domain\TypedCost;
use App\Module\Products\Domain\Product;
use App\Shared\Application\Transactions;
use App\Shared\Domain\DateRange;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use BcMath\Number;
use Symfony\Component\Uid\Uuid;

final class InMemoryStockMovements implements StockMovementRepository
{
    /** @var list<StockMovement> */
    public array $movements = [];
    /** @var list<string> "lock <product> <location>" and "onHand <product> <location>", in call order, with " in transaction" while one is open */
    public array $calls = [];
    public ?Transactions $transactions = null;

    public function save(StockMovement ...$movements): void
    {
        foreach ($movements as $movement) {
            if (!\in_array($movement, $this->movements, true)) {
                RunningValue::of($this->valuedTotalsOf($movement->getProduct()))->take($movement);
            }
            if (!\in_array($movement, $this->movements, true)) {
                $this->movements[] = $movement;
            }
        }
    }

    public function averageCostOf(Product $product): ?string
    {
        $totals = $this->valuedTotalsOf($product);

        return RunningValue::of($totals)->average($product->getDetails()->costPrice);
    }

    public function ofReversing(Uuid $invoiceId, Uuid $companyId): array
    {
        return array_values(array_filter(
            $this->movements,
            static fn (StockMovement $movement): bool => (true === $movement->getReversesSourceId()?->equals($invoiceId)) && $movement->getCompany()->getId()->equals($companyId),
        ));
    }

    public function valuedTotalsBefore(StockMovement $movement): array
    {
        $quantity = new Number('0.000');
        $amount = new Number('0.0000000');
        foreach ($this->movements as $earlier) {
            if ($earlier === $movement) {
                break;
            }
            if ($earlier->getProduct() === $movement->getProduct() && null !== $earlier->getUnitCost()) {
                $quantity = $quantity->add($earlier->getQuantity());
                $amount = $amount->add(new Number($earlier->getQuantity())->mul($earlier->getUnitCost()))->add($earlier->getRevaluation() ?? '0');
            }
        }

        return ['quantity' => $quantity->value, 'amount' => $amount->value];
    }

    public function valuedAfter(StockMovement $movement): array
    {
        $index = array_search($movement, $this->movements, true);
        if (false === $index) {
            return [];
        }

        return array_values(array_filter(
            \array_slice($this->movements, $index + 1),
            static fn (StockMovement $later): bool => $later->getProduct() === $movement->getProduct() && null !== $later->getUnitCost(),
        ));
    }

    public function saveValued(StockMovement $movement): void
    {
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?StockMovement
    {
        return array_find($this->movements, static fn (StockMovement $each): bool => $each->getId()->equals($id) && $each->getCompany()->getId()->equals($companyId));
    }

    public function valuedTotalsOf(Product $product): array
    {
        $quantity = new Number('0.000');
        $amount = new Number('0.0000000');
        foreach ($this->movements as $earlier) {
            if ($earlier->getProduct() === $product && null !== $earlier->getUnitCost()) {
                $quantity = $quantity->add($earlier->getQuantity());
                $amount = $amount->add(new Number($earlier->getQuantity())->mul($earlier->getUnitCost()))->add($earlier->getRevaluation() ?? '0');
            }
        }

        return ['quantity' => $quantity->value, 'amount' => $amount->value];
    }

    public function lastTypedCostOf(Product $product): ?TypedCost
    {
        $latest = null;
        foreach ($this->movements as $movement) {
            if ($movement->getProduct() === $product && $movement->isCostTyped() && (null === $latest || $movement->getAt() >= $latest->getAt())) {
                $latest = $movement;
            }
        }
        $cost = $latest?->getUnitCost();

        return null === $latest || null === $cost ? null : new TypedCost($cost, $latest->getAt());
    }

    public function ofSource(string $sourceType, Uuid $sourceId, Uuid $companyId): array
    {
        return array_values(array_filter(
            $this->movements,
            static fn (StockMovement $m) => $m->getSourceType() === $sourceType && true === $m->getSourceId()?->equals($sourceId) && $m->getCompany()->getId()->equals($companyId),
        ));
    }

    /** What another run does while this one waits for its first lock, once. */
    public ?\Closure $whileWaitingForALock = null;

    public function lockStockOf(Uuid $productId, Uuid $locationId): void
    {
        $other = $this->whileWaitingForALock;
        $this->whileWaitingForALock = null;
        if (null !== $other) {
            $other();
        }
        $this->calls[] = $this->call('lock', $productId, $locationId);
    }

    public function onHand(Uuid $productId, Uuid $locationId, ?Uuid $lotId = null): string
    {
        $this->calls[] = $this->call('onHand', $productId, $locationId);
        $sum = new Number('0.000');
        foreach ($this->movements as $movement) {
            if ($movement->getProduct()->getId()->equals($productId) && $movement->getLocation()->getId()->equals($locationId) && (null === $lotId || true === $movement->getLot()?->getId()->equals($lotId))) {
                $sum = $sum->add($movement->getQuantity());
            }
        }

        return $sum->value;
    }

    public function sellableInEstablishment(Uuid $productId, Uuid $establishmentId): string
    {
        $sum = new Number('0.000');
        foreach ($this->movements as $movement) {
            if ($movement->getProduct()->getId()->equals($productId) && $movement->getLocation()->getEstablishment()->getId()->equals($establishmentId)
                && StockLocationKind::Quarantine !== $movement->getLocation()->getKind()) {
                $sum = $sum->add($movement->getQuantity());
            }
        }

        return $sum->value;
    }

    public function onHandOfLot(Uuid $lotId): string
    {
        $sum = new Number('0.000');
        foreach ($this->movements as $movement) {
            if (true === $movement->getLot()?->getId()->equals($lotId)) {
                $sum = $sum->add($movement->getQuantity());
            }
        }

        return $sum->value;
    }

    public function lotsAt(Uuid $productId, Uuid $locationId): array
    {
        $sums = [];
        $lots = [];
        foreach ($this->movements as $movement) {
            $lot = $movement->getLot();
            if (null !== $lot && $movement->getProduct()->getId()->equals($productId) && $movement->getLocation()->getId()->equals($locationId)) {
                $key = $lot->getId()->toRfc4122();
                $lots[$key] = $lot;
                $sums[$key] = ($sums[$key] ?? new Number('0.000'))->add($movement->getQuantity());
            }
        }

        return array_map(static fn (string $key): LotOnHand => new LotOnHand($lots[$key], $sums[$key]->value), array_keys($sums));
    }

    public function valuation(Uuid $companyId): array
    {
        $rows = [];
        foreach ($this->movements as $movement) {
            if (!$movement->getCompany()->getId()->equals($companyId)) {
                continue;
            }
            $key = $movement->getProduct()->getId()->toRfc4122();
            $cost = $movement->getUnitCost();
            $row = $rows[$key] ?? ['q' => new Number('0.000'), 'v' => new Number('0.0000000'), 'u' => new Number('0.000')];
            $row['q'] = $row['q']->add($movement->getQuantity());
            if (null === $cost) {
                $row['u'] = $row['u']->add($movement->getQuantity());
            } else {
                $row['v'] = $row['v']->add(new Number($movement->getQuantity())->mul($cost))->add($movement->getRevaluation() ?? '0');
            }
            $rows[$key] = $row;
        }

        $values = [];
        foreach ($rows as $key => $row) {
            if (0 !== $row['q']->compare(0) || 0 !== $row['v']->compare(0)) {
                $values[] = new StockValue(Uuid::fromString($key), $row['q']->value, $row['v']->value, $row['u']->value);
            }
        }

        return $values;
    }

    public function totalsOf(Uuid $companyId, array $productIds, ?Uuid $establishmentId = null): array
    {
        $totals = [];
        foreach ($productIds as $id) {
            $totals[$id->toRfc4122()] = new Number('0.000');
        }
        foreach ($this->movements as $movement) {
            $key = $movement->getProduct()->getId()->toRfc4122();
            $there = null === $establishmentId || $movement->getLocation()->getEstablishment()->getId()->equals($establishmentId);
            if ($movement->getCompany()->getId()->equals($companyId) && isset($totals[$key]) && $there) {
                $totals[$key] = $totals[$key]->add($movement->getQuantity());
            }
        }

        return array_map(static fn (Number $total): string => $total->value, $totals);
    }

    public function levels(Uuid $companyId): array
    {
        $sums = [];
        $lots = [];
        foreach ($this->movements as $movement) {
            if (!$movement->getCompany()->getId()->equals($companyId)) {
                continue;
            }
            $key = $movement->getProduct()->getId()->toRfc4122().'|'.$movement->getLocation()->getId()->toRfc4122().'|'.$movement->getLot()?->getId()->toRfc4122();
            $lots[$key] = $movement->getLot();
            $sums[$key] = ($sums[$key] ?? new Number('0.000'))->add($movement->getQuantity());
        }

        return array_map(static function (string $key, Number $quantity) use ($lots): StockLevel {
            [$product, $location] = explode('|', $key);
            $lot = $lots[$key];

            return new StockLevel(Uuid::fromString($product), Uuid::fromString($location), $quantity->value, $lot?->getId(), $lot?->getCode(), $lot?->getExpiresOn()?->format('Y-m-d'), null !== $lot?->getReleasedAt());
        }, array_keys($sums), $sums);
    }

    /**
     * The page the database would answer is the database's own job — grouping, searching and ordering are SQL here,
     * and a unit test that wants them tests the real repository. This one pages what it has totalled, so a caller
     * reading a page still reads rows, and says plainly that it ignores the rest.
     */
    public function searchLevels(Uuid $companyId, StockLevelSearch $search, PageRequest $page): Page
    {
        $levels = $this->levels($companyId);

        return new Page(\array_slice($levels, $page->offset(), $page->size), \count($levels), $page);
    }

    public function searchMovements(Uuid $companyId, StockMovementSearch $search, PageRequest $page): Page
    {
        $words = mb_strtolower(trim($search->text ?? ''));
        $matching = array_values(array_filter(
            $this->movements,
            static fn (StockMovement $m) => $m->getCompany()->getId()->equals($companyId)
                && ([] === $search->products || \in_array($m->getProduct()->getId()->toRfc4122(), array_map(static fn (Uuid $id): string => $id->toRfc4122(), $search->products), true))
                && ([] === $search->locations || \in_array($m->getLocation()->getId()->toRfc4122(), array_map(static fn (Uuid $id): string => $id->toRfc4122(), $search->locations), true))
                && ([] === $search->kinds || \in_array($m->getKind(), $search->kinds, true))
                && ([] === $search->sourceTypes || \in_array($m->getSourceType(), $search->sourceTypes, true))
                && ([] === $search->reasons || \in_array($m->getReason(), $search->reasons, true))
                && (null === $search->costToComplete || $m->isCostToComplete() === $search->costToComplete)
                && (null === $search->movedOn || self::movedOn($m->getAt(), $search->movedOn, $search->timezone))
                && ('' === trim($search->lot ?? '') || mb_strtolower((string) $m->getLot()?->getCode()) === mb_strtolower(trim((string) $search->lot)))
                && ('' === $words || str_contains(mb_strtolower(
                    $m->getProduct()->getReference().' '.$m->getProduct()->getDetails()->name.' '.$m->getLocation()->getCode().' '.$m->getLocation()->getName(),
                ), $words)),
        ));
        // Newest first, as the database orders it, so a test reads the same order either side of the port.
        usort($matching, static fn (StockMovement $a, StockMovement $b) => $b->getAt() <=> $a->getAt());

        return new Page(\array_slice($matching, $page->offset(), $page->size), \count($matching), $page);
    }

    public function countAt(Uuid $locationId): int
    {
        return \count(array_filter($this->movements, static fn (StockMovement $m) => $m->getLocation()->getId()->equals($locationId)));
    }

    public function countOf(Uuid $productId, Uuid $locationId): int
    {
        return \count(array_filter(
            $this->movements,
            static fn (StockMovement $m) => $m->getProduct()->getId()->equals($productId) && $m->getLocation()->getId()->equals($locationId),
        ));
    }

    private function call(string $name, Uuid $productId, Uuid $locationId): string
    {
        return \sprintf('%s %s %s%s', $name, $productId->toRfc4122(), $locationId->toRfc4122(), true === $this->transactions?->active() ? ' in transaction' : '');
    }

    /** The day a movement happened on in the company's calendar, inside the interval: what the database's moments answer. */
    private static function movedOn(\DateTimeImmutable $at, DateRange $range, string $timezone): bool
    {
        $day = $at->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');

        return (null === $range->from || $day >= $range->from) && (null === $range->to || $day <= $range->to);
    }
}

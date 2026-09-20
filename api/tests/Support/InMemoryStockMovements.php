<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockLevelSearch;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Inventory\Domain\StockMovementSearch;
use App\Shared\Application\Transactions;
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
                $this->movements[] = $movement;
            }
        }
    }

    public function ofSource(string $sourceType, Uuid $sourceId, Uuid $companyId): array
    {
        return array_values(array_filter(
            $this->movements,
            static fn (StockMovement $m) => $m->getSourceType() === $sourceType && true === $m->getSourceId()?->equals($sourceId) && $m->getCompany()->getId()->equals($companyId),
        ));
    }

    public function lockStockOf(Uuid $productId, Uuid $locationId): void
    {
        $this->calls[] = $this->call('lock', $productId, $locationId);
    }

    public function onHand(Uuid $productId, Uuid $locationId): string
    {
        $this->calls[] = $this->call('onHand', $productId, $locationId);
        $sum = new Number('0.000');
        foreach ($this->movements as $movement) {
            if ($movement->getProduct()->getId()->equals($productId) && $movement->getLocation()->getId()->equals($locationId)) {
                $sum = $sum->add($movement->getQuantity());
            }
        }

        return $sum->value;
    }

    public function levels(Uuid $companyId): array
    {
        $sums = [];
        foreach ($this->movements as $movement) {
            if (!$movement->getCompany()->getId()->equals($companyId)) {
                continue;
            }
            $key = $movement->getProduct()->getId()->toRfc4122().'|'.$movement->getLocation()->getId()->toRfc4122();
            $sums[$key] = ($sums[$key] ?? new Number('0.000'))->add($movement->getQuantity());
        }

        return array_map(static function (string $key, Number $quantity): StockLevel {
            [$product, $location] = explode('|', $key);

            return new StockLevel(Uuid::fromString($product), Uuid::fromString($location), $quantity->value);
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
                && (null === $search->product || $m->getProduct()->getId()->equals($search->product))
                && (null === $search->location || $m->getLocation()->getId()->equals($search->location))
                && (null === $search->kind || $m->getKind() === $search->kind)
                && (null === $search->sourceType || $m->getSourceType() === $search->sourceType)
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
}

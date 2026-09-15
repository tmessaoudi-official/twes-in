<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use BcMath\Number;
use Symfony\Component\Uid\Uuid;

final class InMemoryStockMovements implements StockMovementRepository
{
    /** @var list<StockMovement> */
    public array $movements = [];

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

    public function ofCompany(Uuid $companyId, int $limit): array
    {
        return self::newestFirst(array_filter($this->movements, static fn (StockMovement $m) => $m->getCompany()->getId()->equals($companyId)), $limit);
    }

    public function ofProduct(Uuid $productId, Uuid $companyId, int $limit): array
    {
        return self::newestFirst(array_filter($this->movements, static fn (StockMovement $m) => $m->getProduct()->getId()->equals($productId) && $m->getCompany()->getId()->equals($companyId)), $limit);
    }

    /**
     * @param array<int, StockMovement> $movements
     *
     * @return list<StockMovement>
     */
    private static function newestFirst(array $movements, int $limit): array
    {
        usort($movements, static fn (StockMovement $a, StockMovement $b) => [$b->getAt(), $b->getId()->toRfc4122()] <=> [$a->getAt(), $a->getId()->toRfc4122()]);

        return \array_slice($movements, 0, $limit);
    }

    public function onHand(Uuid $productId, Uuid $locationId): string
    {
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

    public function countAt(Uuid $locationId): int
    {
        return \count(array_filter($this->movements, static fn (StockMovement $m) => $m->getLocation()->getId()->equals($locationId)));
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Tenancy\Domain\Company;
use BcMath\Number;
use Symfony\Component\Uid\Uuid;

/**
 * Where one product is, as the stock map lights it: what a place holds counts for the nearest drawn place at or above
 * it, so a bin's goods light its rack. What lies under no drawn place is one more answer, never left out: the map is
 * only as true as what it says it cannot show.
 */
final readonly class FindOnMap
{
    public function __construct(
        private StockMovementRepository $movements,
        private StockLocationRepository $locations,
    ) {
    }

    /**
     * The drawn places holding some, ground floor first and by code within a floor, then the undrawn rest if there is
     * any. A place whose movements cancel out holds none and is no answer.
     *
     * @return list<MapHolding>
     */
    public function of(Company $company, Uuid $productId): array
    {
        $byId = [];
        foreach ($this->locations->ofCompany($company->getId()) as $location) {
            $byId[$location->getId()->toRfc4122()] = $location;
        }

        $held = [];
        foreach ($this->movements->levelsOf($company->getId(), $productId) as $level) {
            $key = $level->locationId->toRfc4122();
            $held[$key] = ($held[$key] ?? new Number('0'))->add(self::number($level->quantity));
        }

        $drawn = [];
        $undrawn = [];
        foreach ($held as $key => $quantity) {
            $location = $byId[$key] ?? null;
            if (null === $location || 0 === $quantity->compare(0)) {
                continue;
            }
            $line = new MapHoldingLine($location, self::scaled($quantity));
            $place = self::drawnAbove($location);
            if (null === $place) {
                $undrawn[] = $line;
            } else {
                $drawn[$place->getId()->toRfc4122()][] = $line;
            }
        }

        $holdings = [];
        foreach ($drawn as $key => $lines) {
            $holdings[] = new MapHolding($byId[$key], self::total($lines), self::byCode($lines));
        }
        usort($holdings, static fn (MapHolding $one, MapHolding $other): int => [$one->place?->getSpot()?->getArea()->getLevel(), $one->place?->getCode()] <=> [$other->place?->getSpot()?->getArea()->getLevel(), $other->place?->getCode()]);
        if ([] !== $undrawn) {
            $holdings[] = new MapHolding(null, self::total($undrawn), self::byCode($undrawn));
        }

        return $holdings;
    }

    private static function drawnAbove(StockLocation $location): ?StockLocation
    {
        // A tree is acyclic by construction; the bound only keeps a corrupted one from turning this into a hang.
        for ($at = $location, $steps = 0; null !== $at && $steps < 64; $at = $at->getParent(), ++$steps) {
            if (null !== $at->getSpot()) {
                return $at;
            }
        }

        return null;
    }

    /** @param list<MapHoldingLine> $lines */
    private static function total(array $lines): string
    {
        $sum = new Number('0');
        foreach ($lines as $line) {
            $sum = $sum->add(self::number($line->quantity));
        }

        return self::scaled($sum);
    }

    /**
     * @param list<MapHoldingLine> $lines
     *
     * @return list<MapHoldingLine>
     */
    private static function byCode(array $lines): array
    {
        usort($lines, static fn (MapHoldingLine $one, MapHoldingLine $other): int => $one->location->getCode() <=> $other->location->getCode());

        return $lines;
    }

    /** A quantity as the database sums it; anything else is a fault upstream, never a number to guess at. */
    private static function number(string $quantity): Number
    {
        if (!is_numeric($quantity)) {
            throw new \UnexpectedValueException(\sprintf('A stock quantity reads "%s".', $quantity));
        }

        return new Number($quantity);
    }

    /** Three decimals, the scale every stock quantity is kept at, whatever the sum came to. */
    private static function scaled(Number $quantity): string
    {
        return $quantity->round(3)->value;
    }
}

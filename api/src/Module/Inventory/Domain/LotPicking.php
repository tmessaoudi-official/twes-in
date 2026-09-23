<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use BcMath\Number;

/**
 * Which lots a delivery takes its goods from (docs/SPEC.md § 7, 2026-09-23 02:40): the first to expire leaves first,
 * a lot without a date last, and lots of one date in the order they arrived. An expired lot stays where it is unless
 * someone released it. What no lot in date holds is short: the delivery says so rather than inventing a lot for it.
 */
final readonly class LotPicking
{
    /**
     * @param list<array{StockLot, numeric-string}> $taken each lot and how much leaves it, in the order taken
     * @param numeric-string                        $short what the lots in date could not cover, "0.000" when none
     */
    private function __construct(public array $taken, public string $short)
    {
    }

    /**
     * @param list<LotOnHand>    $available the product's lots at the location
     * @param numeric-string     $quantity  what leaves
     * @param \DateTimeImmutable $today     the company's day
     */
    public static function firstExpiring(array $available, string $quantity, \DateTimeImmutable $today): self
    {
        $candidates = array_values(array_filter(
            $available,
            static fn (LotOnHand $each): bool => 1 === new Number($each->quantity)->compare(0) && $each->lot->deliverableOn($today),
        ));
        usort($candidates, static fn (LotOnHand $a, LotOnHand $b): int => [null === $a->lot->getExpiresOn(), $a->lot->getExpiresOn(), $a->lot->getCreatedAt(), $a->lot->getCode()]
            <=> [null === $b->lot->getExpiresOn(), $b->lot->getExpiresOn(), $b->lot->getCreatedAt(), $b->lot->getCode()]);

        $left = new Number($quantity);
        $taken = [];
        foreach ($candidates as $each) {
            if (1 !== $left->compare(0)) {
                break;
            }
            $take = -1 === $left->compare(new Number($each->quantity)) ? $left : new Number($each->quantity);
            $taken[] = [$each->lot, self::decimal($take)];
            $left = $left->sub($take);
        }

        return new self($taken, self::decimal($left));
    }

    /** @return numeric-string */
    private static function decimal(Number $quantity): string
    {
        return new Number('0.000')->add($quantity)->value;
    }
}

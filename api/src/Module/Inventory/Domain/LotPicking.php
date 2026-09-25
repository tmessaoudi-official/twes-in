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

    /**
     * What a line naming its lot takes (docs/SPEC.md § 7, 2026-09-24 12:40 row 5): that lot and no other, as much as it
     * holds at the location, and never when it expired unreleased. What it cannot cover is short, never taken from
     * another lot, since the record would then say a lot left that nobody handed over.
     *
     * @param list<LotOnHand> $available the product's lots at the location
     * @param numeric-string  $quantity  what leaves
     */
    public static function named(array $available, string $code, string $quantity, \DateTimeImmutable $today): self
    {
        $found = self::find($available, $code);
        if (null === $found || !$found->lot->deliverableOn($today) || 1 !== new Number($found->quantity)->compare(0)) {
            return new self([], self::decimal(new Number($quantity)));
        }
        $wanted = new Number($quantity);
        $take = -1 === $wanted->compare(new Number($found->quantity)) ? $wanted : new Number($found->quantity);

        return new self([[$found->lot, self::decimal($take)]], self::decimal($wanted->sub($take)));
    }

    /**
     * The lot on hand a code names: the one written exactly so, else the one written so whatever its case, since a
     * label read aloud or typed loses the case and the recall search matches it so too.
     *
     * @param list<LotOnHand> $available
     */
    public static function find(array $available, string $code): ?LotOnHand
    {
        $code = trim($code);

        return array_find($available, static fn (LotOnHand $each): bool => $each->lot->getCode() === $code)
            ?? array_find($available, static fn (LotOnHand $each): bool => 0 === strcasecmp($each->lot->getCode(), $code));
    }

    /** @return numeric-string */
    private static function decimal(Number $quantity): string
    {
        return new Number('0.000')->add($quantity)->value;
    }
}

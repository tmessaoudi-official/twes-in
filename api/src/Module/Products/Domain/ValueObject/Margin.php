<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain\ValueObject;

use App\Fiscal\Domain\Calculation\Decimal;

/**
 * What a price without taxes leaves over the cost: the amount, at the four decimals a price is kept at, the share of the
 * price it is, and the share of the cost it is (the markup), each as a percentage to one decimal, the one way every
 * screen reads a margin (a price review, the list), which says the one its reader chose. A price of nothing has no share
 * to give, nor a cost of nothing.
 */
final readonly class Margin
{
    private function __construct(public string $amount, public ?string $percent, public ?string $markup)
    {
    }

    /** Null when there is no cost to count from. */
    public static function of(string $price, ?string $cost): ?self
    {
        if (null === $cost) {
            return null;
        }
        $amount = Decimal::format(Decimal::of($price)->sub(Decimal::of($cost)), 4);

        return new self($amount, Decimal::of($price)->compare(0) > 0 ? self::percentOf($price, $cost) : null, self::markupOf($price, $cost));
    }

    /** The margin as a percentage of a price above nothing, to one decimal. */
    public static function percentOf(string $price, string $cost): string
    {
        $price = Decimal::of($price);
        $left = $price->sub(Decimal::of($cost))->mul(Decimal::of('100'), Decimal::WORKING_SCALE);

        return Decimal::format($left->div($price, Decimal::WORKING_SCALE), 1);
    }

    /** The margin as a percentage of the cost, to one decimal; null for a cost of nothing, which has no share to give. */
    public static function markupOf(string $price, string $cost): ?string
    {
        $base = Decimal::of($cost);
        if ($base->compare(0) <= 0) {
            return null;
        }
        $left = Decimal::of($price)->sub($base)->mul(Decimal::of('100'), Decimal::WORKING_SCALE);

        return Decimal::format($left->div($base, Decimal::WORKING_SCALE), 1);
    }
}

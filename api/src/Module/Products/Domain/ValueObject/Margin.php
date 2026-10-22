<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain\ValueObject;

use App\Fiscal\Domain\Calculation\Decimal;

/**
 * What a price without taxes leaves over the cost: the amount, at the four decimals a price is kept at, and the share of
 * the price it is, as a percentage to one decimal, the one way every screen reads a margin (a price review, the list).
 * A price of nothing has no share to give.
 */
final readonly class Margin
{
    private function __construct(public string $amount, public ?string $percent)
    {
    }

    /** Null when there is no cost to count from. */
    public static function of(string $price, ?string $cost): ?self
    {
        if (null === $cost) {
            return null;
        }
        $amount = Decimal::format(Decimal::of($price)->sub(Decimal::of($cost)), 4);

        return new self($amount, Decimal::of($price)->compare(0) > 0 ? self::percentOf($price, $cost) : null);
    }

    /** The margin as a percentage of a price above nothing, to one decimal. */
    public static function percentOf(string $price, string $cost): string
    {
        $price = Decimal::of($price);
        $left = $price->sub(Decimal::of($cost))->mul(Decimal::of('100'), Decimal::WORKING_SCALE);

        return Decimal::format($left->div($price, Decimal::WORKING_SCALE), 1);
    }
}

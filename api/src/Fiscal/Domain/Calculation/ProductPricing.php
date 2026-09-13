<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

/**
 * A product's price from its cost: net = cost × (1 + profit rate), and the rate back from a typed net. Tax is always
 * computed on the net, never on the cost.
 */
final class ProductPricing
{
    public static function net(string $cost, Rate $profit, int $scale): string
    {
        return Decimal::format(Decimal::of($cost)->mul($profit->fraction()->add(1), Decimal::WORKING_SCALE), $scale);
    }

    /** Undefined, so null, when the cost is zero: there is no rate to show, which is not a rate of zero. */
    public static function rate(string $cost, string $net): ?Rate
    {
        $cost = Decimal::of($cost);
        if (0 === $cost->compare(0)) {
            return null;
        }

        return Rate::fromFraction(Decimal::of($net)->sub($cost)->div($cost, Decimal::WORKING_SCALE));
    }

    public static function tax(string $net, Rate $rate, int $scale): string
    {
        return Decimal::format(Decimal::of($net)->mul($rate->fraction(), Decimal::WORKING_SCALE), $scale);
    }

    public static function gross(string $net, string $tax, int $scale): string
    {
        return Decimal::format(Decimal::of($net)->add(Decimal::of($tax)), $scale);
    }
}

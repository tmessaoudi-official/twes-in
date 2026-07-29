<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Pricing\Exception;

use Twes\Domain\Money\Money;

final class InvalidCost extends \InvalidArgumentException
{
    /**
     * A product's cost below zero is refused.
     *
     * `Money` itself must allow negatives — a credit note is a negative document — so this is a *pricing*
     * rule rather than a money rule. The real case a negative cost might be mistaken for is already covered
     * properly: selling below cost is a negative **rate**, which `Rate` explicitly allows.
     *
     * **Note what this rule is NOT**, because an earlier version of this message said it was. It does not
     * prevent a negative *selling price*: `fromNetPrice(100.000, -50.000)` and a rate of -150% are both
     * accepted, and a cost edit carries the negative price forward. Whether that should be refused is an
     * open question; stating a rule the domain does not hold is how the next reader fixes the wrong thing.
     */
    public static function negative(Money $cost): self
    {
        return new self(\sprintf(
            'A product cost of %s is negative, which is not a commercial state. Selling below cost is '
            . 'expressed as a negative profit RATE, which is allowed and is probably what was meant.',
            $cost->amount(),
        ));
    }

    /**
     * The net price this cost and rate imply cannot be held by a `Money`.
     *
     * Refused when the pair is *combined* rather than when the price is *read*, because `netPrice()` is an
     * accessor and an accessor must not throw on state that was persisted legally. The two bounds being
     * individually satisfied says nothing about their product: a 0.001 cost with a 99999999 rate is two
     * perfectly storable values whose product needs sixteen integer digits.
     */
    public static function netPriceWouldNotBeRepresentable(
        \Twes\Domain\Money\Money $cost,
        \Twes\Domain\Pricing\Rate $profitRate,
        string $product,
    ): self {
        return new self(\sprintf(
            'A cost of %s at a profit rate of %s %% implies a net price of %s, which needs more than %d '
            . 'digits before the decimal point and cannot be stored. Refused here rather than when the price '
            . 'is read, because reading a price must never fail for a product that saved successfully. A '
            . 'rate this large usually means the cost is near zero — check the cost.',
            $cost->amount(),
            $profitRate->percentage(),
            $product,
            \Twes\Domain\Money\Money::MAX_INTEGER_DIGITS,
        ));
    }
}

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
}

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
     * rule rather than a money rule. A negative cost silently yields a negative selling price, and the real
     * case it might be mistaken for is already covered properly: selling below cost is a negative **rate**,
     * which `Rate` explicitly allows.
     */
    public static function negative(Money $cost): self
    {
        return new self(\sprintf(
            'A product cost of %s is negative. Selling below cost is expressed as a negative profit RATE, '
            . 'which is allowed; a negative cost is not a commercial state and would silently produce a '
            . 'negative selling price.',
            $cost->amount(),
        ));
    }
}

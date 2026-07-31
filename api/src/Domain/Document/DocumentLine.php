<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Document;

use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\Decimal;

/**
 * One line of a document: a quantity, a unit price net of VAT, and the VAT rate that applies to it.
 *
 * **The quantity is a decimal STRING, not an int and never a float.** A quantity is routinely fractional —
 * 2.5 hours, 0.750 kg, 1.5 days — and it multiplies a money amount, so a float here would reintroduce the
 * one defect this domain exists to prevent at the exact point where it does the most damage. `Money` already
 * refuses a float by signature; this refuses one for the same reason.
 *
 * The rate lives on the LINE rather than the document. A document-level rate is the *default* a caller
 * applies when building lines, which is how the shared vectors express the single-rate cases — but multiple
 * rates on one document are the normal Tunisian and French case, so the line is where the rate belongs.
 *
 * Immutable, like everything else in this domain: an issued document's figures can never be moved by a later
 * edit to the product it was built from.
 */
final readonly class DocumentLine
{
    /**
     * @param string $quantity decimal string or integer-valued string; never a float
     *
     * @throws \InvalidArgumentException if the quantity is malformed or negative
     */
    public function __construct(
        private string $quantity,
        private Money $unitNet,
        private Rate $vatRate,
    ) {
        if (!Decimal::isWellFormed($quantity)) {
            throw new \InvalidArgumentException(\sprintf(
                'Quantity "%s" is not a well-formed decimal. A quantity is a decimal string — never a '
                . 'float, because it multiplies a money amount.',
                $quantity,
            ));
        }

        // A negative quantity is how a credit note gets expressed in some systems, and that is precisely why
        // it is refused HERE: twes-in has a Credit document type (Wave 2), so a negative line on an invoice
        // would be a second, unmodelled way to say the same thing — and the two would round differently.
        if (Decimal::isNegative($quantity)) {
            throw new \InvalidArgumentException(\sprintf(
                'Quantity "%s" is negative. A credit is its own document type (Wave 2), not a negative '
                . 'line on an invoice — two ways to express one thing is how the two drift apart.',
                $quantity,
            ));
        }
    }

    public function quantity(): string
    {
        return $this->quantity;
    }

    public function unitNet(): Money
    {
        return $this->unitNet;
    }

    public function vatRate(): Rate
    {
        return $this->vatRate;
    }

    /**
     * The line's net: quantity times unit price, rounded to the currency once.
     *
     * Rounded HERE and not left exact, because the line net is a figure that is **printed on the document
     * and summed into the subtotal**. Keeping it exact and rounding only at the end would make the printed
     * lines fail to add up to the printed subtotal, which is the single most common complaint about
     * generated invoices and, for an EN 16931 payload, a validation failure rather than a cosmetic one.
     */
    public function net(\Twes\Domain\Shared\RoundingMode $mode): Money
    {
        return $this->unitNet->multipliedBy($this->quantity, $mode);
    }
}

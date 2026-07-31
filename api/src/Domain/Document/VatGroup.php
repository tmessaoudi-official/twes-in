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

use Twes\Domain\Money\Exception\CurrencyMismatch;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;

/**
 * One row of a document's VAT breakdown: a rate, the net base it applies to, and the VAT on that base.
 *
 * A deliverable in its own right rather than an intermediate value. This breakdown is what appears on the
 * printed document, and it is the RATE-AND-AMOUNT half of an EN 16931 / UBL `TaxSubtotal` — so a document whose
 * *total* is right while its breakdown is wrong is still a rejected invoice.
 *
 * **It is NOT yet a complete `TaxSubtotal`, and the claim is narrowed here rather than left to be read once and
 * believed** (round 13). A conformant `TaxSubtotal` also needs **BT-118**, the VAT category code — `S`
 * (standard), `Z` (zero-rated), `E` (exempt), `AE` (reverse charge), `O` (out of scope) — and for `E`/`AE`/`O`
 * it needs **BT-120**, the exemption reason. A 0% group carries no way to say which of those it is, and
 * zero-rated, exempt and intra-EU reverse charge are legally different things that are routinely conflated in
 * code. The category code lands with e-invoicing in **Wave 5**; until then a zero-rate group is correctly
 * grouped and incompletely described.
 */
final readonly class VatGroup
{
    /**
     * **THE GUARDS ARE HERE BECAUSE THIS IS A SECOND DOOR, and round 14 found it standing open.**
     * `DocumentLine` refuses a negative VAT rate with the argument that `Rate` cannot — it also serves as the
     * profit rate, where a negative value is meaningful — so "what a VAT rate may be is a property of
     * documents" and the constraint belongs at the use site. This class is equally a document use site: it
     * takes a raw `Rate` and a raw `Money`, so `DocumentLine`'s guard is bypassed entirely, and the object
     * that bypasses it is the one that becomes the legal EN 16931 / UBL `TaxSubtotal`.
     *
     * That is verbatim the shape `DocumentLine` itself records closing one door earlier — *"`DocumentLine`
     * takes a raw `Money`, so `ProductPricing`'s two guards are bypassed entirely and a third guard is needed
     * rather than a third comment about the first two."* Third comment, third guard.
     *
     * @throws \InvalidArgumentException if the rate is negative
     * @throws CurrencyMismatch if the base and the VAT are not in the same currency
     */
    public function __construct(
        private Rate $rate,
        private Money $base,
        private Money $vat,
    ) {
        if ($rate->isNegative()) {
            throw new \InvalidArgumentException(\sprintf(
                'A VAT breakdown group cannot carry a negative rate, got %s%%. A negative tax rate is not a '
                . 'thing a tax authority recognises: a refund is a CREDIT NOTE (EN 16931 type code 381) with '
                . 'positive rates, not an invoice with negative ones. `Rate` permits negatives because it also '
                . 'serves as the profit rate, so the constraint belongs here, at the use site.',
                $rate->percentage(),
            ));
        }

        // BASE AND VAT IN ONE CURRENCY. `Money` refuses to ADD across currencies, but nothing stops two
        // differently-denominated amounts being STORED side by side — and this pair is summed into the
        // document total downstream and rendered as one `TaxSubtotal` row, where a EUR VAT figure beside a
        // TND base is a legal document stating tax that was never owed in either currency.
        if (!$base->currency()->equals($vat->currency())) {
            throw CurrencyMismatch::between($base->currency(), $vat->currency());
        }
    }

    public function rate(): Rate
    {
        return $this->rate;
    }

    /** The summed net of every line carrying this rate. */
    public function base(): Money
    {
        return $this->base;
    }

    public function vat(): Money
    {
        return $this->vat;
    }
}

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
    public function __construct(
        private Rate $rate,
        private Money $base,
        private Money $vat,
    ) {}

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

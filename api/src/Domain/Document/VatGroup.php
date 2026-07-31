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
 * printed document and what goes into an EN 16931 / UBL payload as `TaxSubtotal` — so a document whose
 * *total* is right while its breakdown is wrong is still a rejected invoice.
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

<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\ApiResource;

/**
 * A fixed charge on the wire — a flat amount that is not a priced line.
 *
 * Tunisia's stamp duty is the canonical case: `0.100 TND`, exactly 100 millimes, and it carries no quantity, no
 * unit price and no VAT rate. Modelling it as a line with quantity 1 would put a VAT rate on something that has
 * none and would make it participate in rate-group allocation.
 *
 * `amount` is a DECIMAL STRING for the reason {@see InvoiceLineResource} gives at length: JSON's only number type
 * is a double, and `0.100` through a float is no longer exactly 100 millimes.
 */
final readonly class FixedChargeResource
{
    public function __construct(
        /** Contiguous from 0, re-indexed by `Invoice::withoutFixedCharge()` exactly as lines are. */
        public int $position,
        /** A machine-side identifier such as `stamp_duty`, not a translated label — the client renders it. */
        public string $label,
        /** Decimal string, in the document's currency. */
        public string $amount,
    ) {}
}

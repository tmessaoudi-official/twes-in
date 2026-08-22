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
 * A product's pricing, as the API returns it. **Output only** — the input is on {@see NewProductInput} itself.
 *
 * ## Both derived and authored figures are returned, and `authoredBy` says which is which
 *
 * `profitRate` and `netPrice` are BOTH present on the way out even though only one is stored. That is not a
 * contradiction of F4 — it is what F4 asks for. The plan's § "Bidirectional editing" requires a form showing
 * three linked fields where none can lie, so a client needs all three; what it must never do is treat the
 * derived one as authoritative. `authoredBy` is how it tells them apart, and it is why that field is on the wire
 * at all rather than being an implementation detail.
 *
 * **A CLIENT MUST NOT WRITE BACK THE DERIVED FIELD.** Doing so transfers authorship to it, which is a real
 * decision (F4: a typed price against an old cost is no longer a statement about a new one) and must be one the
 * user made rather than one a round trip made for them.
 *
 * ## Every figure is a decimal STRING, never a JSON number
 *
 * `CLAUDE.md` is explicit and this is the surface where it bites hardest: JSON has one number type and it is a
 * double, so `0.1` is not representable and `0.100 TND` — Tunisia's stamp duty, exactly 100 millimes — stops
 * being exact. The rate is worse: F4's own example needs SEVEN decimal places to express one millime of profit
 * on a ten-thousand-dinar cost, and a double would round it away silently.
 */
final readonly class ProductPricingResource
{
    public function __construct(
        /** ISO 4217 alpha-3. Both amounts are in this currency — a product cannot mix two. */
        public string $currency,
        /** What the item cost. Always authoritative: it is never derived under either authorship. */
        public string $cost,
        /**
         * Which field the user typed: `profit_rate` or `net_price`.
         *
         * The backed value rather than the enum, matching every other enum on this contract: the wire is a
         * contract and an enum's PHP identity is ours.
         */
        public string $authoredBy,
        /**
         * The profit rate as a PERCENTAGE — `30` is thirty percent, not `0.3`.
         *
         * Ten decimal places, and F4 is explicit that this is **not a display format**: it is the canonical
         * value, so comparing a rate across PHP, TypeScript and Dart stays an exact string comparison. Clients
         * format for the locale.
         *
         * **`null` WHEN IT IS GENUINELY UNDEFINED**, which is a zero cost — the rate would be a division by
         * zero. F4 rules that the field then shows empty, never `0` and never an error, because a product sold
         * for something with no cost has no meaningful margin percentage.
         */
        public ?string $profitRate,
        /** The selling price before VAT. */
        public string $netPrice,
    ) {}
}

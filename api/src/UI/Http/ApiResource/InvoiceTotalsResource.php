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
 * A document's computed totals on the wire. All decimal strings.
 *
 * **SERVED RATHER THAN LEFT TO THE CLIENT, and that is the decision worth defending.** Three clients — Angular,
 * Flutter and whatever a customer integrates — could each compute these from the lines. They must not: the
 * calculation involves a rounding point (`PerRateGroup` versus `PerLine`), a rounding mode, and a largest-remainder
 * allocation with ties to the earliest line. Three independent implementations of that is three chances to disagree
 * about a legal document's total, and `CLAUDE.md` names upstream maintaining four parallel tax classes that must be
 * kept numerically in step by hand as a large part of why its test suite is 167k lines.
 *
 * So the server computes and the clients display. `docs/spec/pricing-vectors.json` exists so that a client which
 * *does* compute — to preview a total before saving, which is legitimate — agrees with the server exactly.
 *
 * **`vatByRate` is keyed by the rate as a decimal string**, not by an index, because the key IS the meaningful
 * identity of a group: `"19"` and `"7"` are what an invoice prints and what a tax return aggregates by.
 */
final readonly class InvoiceTotalsResource
{
    public function __construct(
        /** Sum of the line nets. Excludes fixed charges — they are not priced lines. */
        public string $subtotalNet,
        /**
         * VAT per rate group, keyed by the rate as a decimal string.
         *
         * Under `PerRateGroup` — the default — each group's VAT is rounded ONCE on the summed base, which is why
         * these values are authoritative and the per-line figures are an allocation of them.
         *
         * @var array<string, string>
         */
        public array $vatByRate,
        /** Sum of `vatByRate`. */
        public string $vatTotal,
        /** Sum of the fixed charges. */
        public string $fixedChargesTotal,
        /** `subtotalNet + vatTotal + fixedChargesTotal`. What the client owes. */
        public string $total,
        /**
         * Which rounding point produced these figures — `per_rate_group` or `per_line`.
         *
         * **Served because it is PERSISTED PER DOCUMENT.** A company changing its setting must not restate a
         * document a client already holds, so the value travels with the document rather than being a property of
         * the account — and a client that re-computes needs to know which rule to apply.
         */
        public string $vatRoundingPoint,
    ) {}
}

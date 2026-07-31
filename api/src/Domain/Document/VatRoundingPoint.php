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

/**
 * WHERE VAT is rounded — a parameter, never a parallel class hierarchy.
 *
 * `pricing-and-documents.plan.md` rules the rounding point "configurable per company, with [per rate group]
 * as the default", so both arms are supported configuration rather than one real path and one dead one.
 *
 * The difference is not cosmetic and the shared vectors prove it: two lines of 0.013 TND at 19% give
 * **0.005** rounded once on the summed base and **0.004** rounded per line. On a real document that is a
 * millime of tax owed to the state, computed differently by two tiers of the same product.
 *
 * `PerRateGroup` is the default because it is what an **EN 16931 / Peppol validator recomputes** — a payload
 * built the other way arrives at a different figure and needs reconciliation, which is a rejected invoice
 * rather than a rounding preference.
 *
 * This enum is the whole of the parameterisation. CLAUDE.md § Architecture: upstream maintains
 * `InvoiceSum`/`InvoiceSumInclusive` plus `InvoiceItemSum`/`InvoiceItemSumInclusive` as four classes kept
 * numerically in step by hand, and that duplication is a large part of why their test suite is 167k LOC.
 */
enum VatRoundingPoint
{
    /** Sum every line sharing a rate, then round that group's VAT ONCE. The default. */
    case PerRateGroup;

    /** Round each line's VAT, then sum. Supported, and numerically different — see the class docblock. */
    case PerLine;
}

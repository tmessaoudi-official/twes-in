<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Pricing;

/**
 * Which of a product's two priceable fields the user actually typed.
 *
 * Persisted alongside the product, because it is not a UI detail: it decides which value is authoritative
 * and which is merely displayed, and therefore which one may be recomputed. Without it, both fields look
 * equally real and the derived one gets rebuilt from a rounded copy of the other.
 */
enum PricedBy: string
{
    /** The user typed a profit rate; the selling price follows from it. */
    case ProfitRate = 'profit_rate';

    /** The user typed a selling price; the profit rate is derived for display. */
    case NetPrice = 'net_price';

    /**
     * The length of the longest backed value, which is what a column storing one has to be wide enough for.
     *
     * **A LITERAL BECAUSE AN ATTRIBUTE ARGUMENT MUST BE A CONSTANT EXPRESSION, and pinned by a test because a
     * literal beside the thing it measures is the first thing to drift.** `PricedByTest` asserts it equals the
     * longest `strlen($case->value)`, so adding a case with a longer name fails there rather than at an INSERT.
     *
     * It exists BEFORE the column does, deliberately. `CLAUDE.md` § Gotchas records `CompanySettingsRow` mapping
     * `default_vat_rounding_point` as `length: 32` while its migration derived `varchar(14)` from the enum's own
     * cases — a mapping and a schema disagreeing about one column, which `doctrine:schema:validate --skip-sync`
     * cannot see BY DESIGN, since `--skip-sync` is exactly what stops it comparing against a database. That
     * mismatch had no automatic detector, so the remedy is one source both sides read, written before the second
     * side exists rather than after they have already disagreed.
     */
    public const int MAX_BACKED_VALUE_LENGTH = 11;
}

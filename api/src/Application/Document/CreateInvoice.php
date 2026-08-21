<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Application\Document;

use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Money\Currency;

/**
 * "Create a draft invoice with these lines." A command — data, no behaviour.
 *
 * **IT CARRIES DOMAIN TYPES, NOT THE WIRE'S STRINGS, and that placement is the decision worth explaining.** The
 * obvious alternative is a command of primitives (`string $currency`, `list<array{quantity: string, …}>`) with the
 * handler converting. It was rejected because the conversion is a PARSE — turning `"19"` into a `Rate`, `"TND"` into
 * a `Currency` — and parsing untrusted text into the domain's vocabulary is what a transport boundary is for. Doing
 * it in the handler would mean every future caller (a CLI import, a Messenger consumer) had to reproduce the same
 * string handling, and the errors it raises are the ones a client needs to see as a 422, which only the boundary can
 * turn into a response.
 *
 * So the boundary parses and this command is already valid by construction. The residual cost, stated: a caller who
 * builds one of these by hand can still assemble a combination the aggregate refuses — a line in another currency,
 * say — and finds out when {@see CreateInvoiceHandler} runs. That is correct, because the aggregate is where those
 * cross-field invariants live and duplicating them here would be a second copy.
 *
 * **THE ROUNDING POINT IS NOT A FIELD HERE, AND IT USED TO BE.** This paragraph argued the opposite — that a
 * default hidden in the handler would be "this layer quietly deciding a tax question", so the caller should state
 * it — and it ended by admitting the real reason: *"today every caller states `PerRateGroup` because there is no
 * settings table yet"*. That table landed, so the sentence is inverted in place rather than annotated
 * (`CLAUDE.md` § Gotchas 2026-07-29).
 *
 * The argument for removing it is stronger than "the placeholder expired". The ruling of 2026-08-07 is that a
 * **client may not choose how much tax a document declares**, which is why {@see \Twes\UI\Http\ApiResource\NewInvoiceInput}
 * has no such field. But a field on this command left every *programmatic* caller — a CLI import, a Messenger
 * consumer, a future recurring-invoice scheduler — free to state it, so the ruling held at the HTTP boundary and
 * nowhere else. {@see CreateInvoiceHandler} now reads it from {@see \Twes\Domain\Settings\CompanySettingsRepository},
 * which closes that gap for every caller at once instead of one boundary at a time.
 *
 * It is still **persisted per document**, so a company changing its setting cannot restate a document a client
 * already holds — that half is unchanged, and it is the byte-identical-re-download guarantee.
 */
final readonly class CreateInvoice
{
    /**
     * @param list<DocumentLine> $lines in the order they should appear on the document
     * @param list<FixedCharge> $fixedCharges
     */
    public function __construct(
        public Currency $currency,
        public array $lines,
        public array $fixedCharges,
    ) {}
}

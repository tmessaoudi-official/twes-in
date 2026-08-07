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

use Symfony\Component\Validator\Constraints as Assert;
use Twes\Domain\Document\Invoice;

/**
 * The body of `POST /api/invoices`. **Input only** — the response is an {@see InvoiceResource}.
 *
 * ## What a client may decide, and what it may not
 *
 * **IT MAY NOT DECIDE THE DOCUMENT ID.** The id is generated server-side from {@see \Twes\Domain\Shared\IdGenerator}.
 * Accepting one would let a caller choose a primary key, which is an existence oracle across tenants (a 409 tells you
 * somebody else already has that id) and, once the Wave 10 client portal exists, a way to aim at a URL.
 *
 * **IT MAY NOT DECIDE THE DOCUMENT NUMBER.** `POST` creates a DRAFT, which has no number at all: a number is
 * allocated at issue, from a gapless per-`(tenant, type)` counter, so an abandoned draft cannot consume one.
 * `POST /api/invoices/{id}/issue` is the transition.
 *
 * **IT MAY NOT DECIDE WHERE VAT IS ROUNDED.** `vatRoundingPoint` is absent from this DTO on purpose, and the
 * omission is a tax decision rather than a simplification: `PerRateGroup` and `PerLine` produce *numerically
 * different* tax figures, so a client choosing per request would be a client choosing how much tax a document
 * declares. It is company configuration — persisted per document precisely so a later settings change cannot restate
 * a document a client already holds — and until the settings table exists every document gets
 * {@see \Twes\Domain\Document\VatRoundingPoint::PerRateGroup}. Consequence, stated rather than discovered:
 * `PerLine` is unreachable over HTTP today.
 *
 * **IT MAY DECIDE THE CURRENCY**, because the currency is a property of the transaction and not of the seller.
 *
 * ## Validation
 *
 * Structural only — see {@see NewInvoiceLineInput} for the full argument and for why the messages are Symfony's own
 * defaults. `#[Assert\Valid]` is what makes the constraints on the nested DTOs run at all: without it the collection
 * is checked for its own constraints and its elements are not, which is a validator that reports clean on invalid
 * input. Every real bound stays in the domain.
 */
final readonly class NewInvoiceInput
{
    /**
     * @param list<NewInvoiceLineInput> $lines
     * @param list<NewFixedChargeInput> $fixedCharges
     *
     * **THE `@param` TYPES ARE LOAD-BEARING AND NOT DOCUMENTATION.** PHP has no generics, so `array` is all the
     * Serializer and the OpenAPI schema factory can see from the signature; both read the element type out of these
     * annotations, which is why `phpstan/phpdoc-parser` and `phpdocumentor/type-resolver` are runtime dependencies
     * rather than dev ones. Without them the nested objects arrive as raw arrays, `#[Assert\Valid]` cascades onto
     * nothing, and the published request schema documents `lines` as an untyped array — a contract document that is
     * wrong about the contract. [Verified: `property_info.phpstan_extractor` is registered only when both packages
     * are non-dev requirements — `FrameworkExtension.php:2193-2196`.]
     */
    public function __construct(
        /**
         * ISO 4217 alpha-3, e.g. `TND`, `EUR`. Uppercase.
         *
         * `Currency::of()` owns the real check — which codes exist and what scale each one has — and refuses an
         * unknown code rather than assuming two decimals. The bound here is only the shape, so that `"currency": 12`
         * or `"EURO"` is a validation failure naming the field instead of a domain exception.
         */
        #[Assert\NotBlank]
        #[Assert\Length(exactly: 3)]
        #[Assert\Regex(pattern: '/^[A-Z]{3}$/D')]
        public string $currency,
        /**
         * At least one line, at most {@see Invoice::MAX_LINES}.
         *
         * **The minimum is 1 even though a DRAFT may legitimately be empty**, and that asymmetry is deliberate: the
         * aggregate permits an empty draft because a draft is something under construction, while this endpoint
         * creates a document in a single request from a client that already knows its contents. An empty invoice can
         * never be issued (`DocumentCannotBeIssued`), so accepting one here would only manufacture a document whose
         * one remaining transition is guaranteed to fail.
         *
         * The maximum is the domain's constant, referenced rather than repeated. Checking it at the edge as well as
         * in `Invoice::withLine()` is what turns a 1001-line payload into one message about the payload instead of a
         * refusal on the thousand-and-first `withLine()` call, after a thousand `totals()` computations — which
         * `Invoice` measures at roughly 18 seconds of CPU for a thousand lines.
         */
        #[Assert\Count(min: 1, max: Invoice::MAX_LINES)]
        #[Assert\Valid]
        public array $lines,
        /**
         * Fixed charges — a stamp duty, a late fee. Optional, and bounded by the same constant as the lines because
         * `Invoice::withFixedCharge()` applies `assertRoomFor()` to both.
         */
        #[Assert\Count(max: Invoice::MAX_LINES)]
        #[Assert\Valid]
        public array $fixedCharges = [],
    ) {}
}

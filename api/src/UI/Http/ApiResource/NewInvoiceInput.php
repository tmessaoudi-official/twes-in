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
 * a document a client already holds — read from `company_settings` by {@see \Twes\Application\Document\CreateInvoiceHandler}.
 * This paragraph used to end *"until the settings table exists every document gets `PerRateGroup` … `PerLine` is
 * unreachable over HTTP today"*, which stopped being true when that table landed: a company configured for `PerLine`
 * now gets it, on documents created through this DTO, without the DTO gaining a field.
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
     * The shape of a canonical identifier, mirroring {@see \Twes\Domain\Shared\Identifier}'s own rule.
     *
     * **DUPLICATED DELIBERATELY, AND PINNED BY A TEST RATHER THAN BY CARE.** The domain's copy is `private` and
     * belongs to `Domain/`, which this layer may depend on but may not reach into; publishing it purely to let the
     * edge borrow it would widen a domain API for a transport's convenience. So the rule is stated twice and
     * `NewInvoiceInputTest::testTheEdgeAndTheDomainAgreeOnWhatAnIdentifierIs()` asserts the two agree across
     * uppercase, braced, over-long and empty spellings — a drift here would otherwise show up as a 500 where a
     * 422 was intended, which is precisely the class of defect this project keeps finding.
     *
     * **NOT `#[Assert\Uuid]`.** That constraint accepts the uppercase and braced forms, and the domain refuses
     * both rather than normalising them, on the ground that an identifier is a key and not a display value. Using
     * it would admit spellings the aggregate then rejects — moving the refusal from a 422 naming `clientId` to a
     * 500 raised inside the handler, outside this processor's `try`.
     */
    public const string CANONICAL_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

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
        /**
         * The client this invoice is addressed to. **Optional here, and required at issue.**
         *
         * The asymmetry is the ruling rather than an accident of a nullable column: a DRAFT may have no client for
         * the same reason it may have no lines — deciding who an invoice is for can come after typing what is on
         * it — while `Invoice::issue()` demands one, because EN 16931 makes the buyer mandatory (BT-44) and an
         * issued invoice addressed to nobody is not a document a tax authority accepts. The requirement attaches
         * to the TRANSITION, not to the type.
         *
         * **AN ID, NEVER A NESTED CLIENT OBJECT.** One aggregate references another by identity. Accepting a
         * client body here would let one request create or restate a client as a side effect of creating an
         * invoice, which is two decisions wearing one endpoint — and it is the same argument that keeps
         * `Client::withContact()` off this surface.
         *
         * Only the SHAPE is checked here. Whether the id names a client THIS TENANT can see is a question only the
         * database can answer, and it answers it through the composite foreign key — see
         * {@see \Twes\Domain\Document\Exception\UnknownClient}.
         *
         * **`NotBlank` BESIDE THE REGEX, BECAUSE THE REGEX CANNOT REFUSE AN EMPTY STRING.**
         * `RegexValidator::validate()` opens with `if (null === $value || '' === $value) { return; }`, so `""` is
         * SKIPPED by the pattern rather than matched against it. Absent this, `{"clientId": ""}` walked past the edge
         * untouched, was passed through `command()` unchanged, and reached `withClient()` inside the handler — where
         * `'' !== null` and the id is not well-formed, so the `\InvalidArgumentException` landed OUTSIDE the
         * conversion's `catch` and the caller was told the server broke. Every other malformed spelling answered 422.
         *
         * `allowNull: true` because ABSENT and EMPTY are different: a draft legitimately has no client, so `null`
         * must stay acceptable. What is refused is a field that is PRESENT and says nothing.
         */
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Regex(pattern: self::CANONICAL_ID)]
        public ?string $clientId = null,
    ) {}
}

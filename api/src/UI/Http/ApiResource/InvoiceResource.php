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

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Twes\UI\Http\State\CreateInvoiceProcessor;
use Twes\UI\Http\State\InvoiceProvider;
use Twes\UI\Http\State\IssueInvoiceProcessor;

/**
 * An invoice on the wire. **Three operations, and what is absent is as deliberate as what is present.**
 *
 * The first TENANT-SCOPED resource, which is what makes it different from `CurrencyResource`: every read and every
 * write goes through `InvoiceRepository`, which refuses to touch an aggregate with no tenant bound, and every row it
 * can see is filtered by PostgreSQL row-level security. A request that proves no tenant gets nothing — not an error
 * naming the document, which would itself be a leak.
 *
 * **THE LIFECYCLE IS TWO OPERATIONS, matching the aggregate's own two transitions:**
 * - `POST /api/invoices` creates a **draft**, which has no number. See {@see NewInvoiceInput} for what a client may
 *   and may not decide — not the id, not the number, and not where VAT is rounded.
 * - `POST /api/invoices/{id}/issue` **issues** it, which allocates a number from the gapless per-`(tenant, type)`
 *   counter and freezes the document. A separate operation rather than an `"issue": true` flag on create, because a
 *   flag would make an irreversible act reachable two ways.
 *
 * **NO `Put`, `Patch` OR `Delete`, and none of those is an oversight:**
 * - **No `Delete`.** An issued document is never deleted; it is `cancel()`ed, which is a state transition the
 *   aggregate models behind a guard. A delete endpoint would put a way to destroy a legal document beside the way
 *   to void one — and `InvoiceRepository` deliberately declares no `delete()` for the same reason.
 * - **No `Put`/`Patch`.** An issued document is immutable, and the aggregate refuses to mutate once issued. A
 *   partial-update endpoint would be a way to ask for something the domain must refuse, so the refusal belongs in
 *   the route table rather than in an error response. **The consequence for a DRAFT is stated rather than hidden:**
 *   a draft is mutable in the domain (`withLine()`, `withoutLine()`) and there is no way to edit one over HTTP, so a
 *   client builds the whole document and posts it once. Editing a draft is a contract addition, and it wants a shape
 *   decision — a whole-document `PUT` versus line sub-resources — rather than being added incidentally here.
 * - **No `GetCollection`.** Listing needs pagination, filtering and a sort order, and an aggregate repository is
 *   the wrong place for those — they belong to a read model and to API Platform's own pagination extension.
 *   Shipping an unpaginated collection of a table that GROWS is the mistake `CurrencyResource` explicitly calls
 *   itself the exception to.
 *
 * **`stateless: true`** for the same reason every other resource here sets it: no endpoint may establish a
 * session, and a functional test asserts no endpoint sets a session cookie.
 */
#[ApiResource(
    shortName: 'Invoice',
    description: 'An invoice belonging to the current tenant, with its lines, fixed charges and computed totals.',
    operations: [
        new Get(uriTemplate: '/invoices/{id}'),
        new Post(
            uriTemplate: '/invoices',
            // THE REQUEST BODY IS ITS OWN CLASS, not this resource. This one's constructor requires `totals`, which
            // is computed — so reusing it would either invite a client to supply a tax figure or make half its
            // fields optional and leave a reader unable to tell inputs from outputs.
            input: NewInvoiceInput::class,
            // COLLECT THE TYPE ERRORS INSTEAD OF ABORTING ON THE FIRST ONE, and this is a contract decision rather
            // than a tuning knob. Without it, `"unitNet": 19.99` — a JSON number where a decimal string belongs —
            // produces `400 {"detail":"The input data is misformatted."}` and names NO field, so a client is told its
            // payload is wrong and not where. With it, the Serializer records each type mismatch as a constraint
            // violation and the answer is a 422 carrying `lines[0].unitNet`. That matters more here than in most APIs:
            // money-is-never-a-float means a JSON number is the single most likely mistake a client will make against
            // this endpoint, and the wire form is the one rule three clients have to get right.
            denormalizationContext: [AbstractObjectNormalizer::COLLECT_DENORMALIZATION_ERRORS => true],
            processor: CreateInvoiceProcessor::class,
        ),
        new Post(
            uriTemplate: '/invoices/{id}/issue',
            // `input: false` — there is nothing to send. Every part of a document number is the server's, and a
            // client that could name one could pick a legal document number.
            input: false,
            // `read: false` because the processor's handler must do the lookup INSIDE its own transaction: the
            // counter's `SELECT … FOR UPDATE` locks the sequence row for the life of that transaction, so a read
            // performed by API Platform beforehand would be a separate transaction observing a separate state.
            read: false,
            // 200, not the `Post` default of 201: this creates nothing. It transitions a document that exists, and
            // the response is that same document at its new state.
            status: 200,
            processor: IssueInvoiceProcessor::class,
        ),
    ],
    provider: InvoiceProvider::class,
    stateless: true,
)]
final readonly class InvoiceResource
{
    public function __construct(
        /** The document's UUID, canonical lowercase. Also the resource identifier. */
        public string $id,
        /**
         * `draft`, `issued` or `cancelled` — the backed value, not a translated label.
         *
         * A client renders it through `document.state.*`, which exists because interpolating a wire value into a
         * sentence produces *"Ce document est issued"* in French and an English token in Arabic.
         */
        public string $state,
        /** ISO 4217 alpha-3. Fixed per document; a line in another currency is refused by the domain. */
        public string $currency,
        /**
         * The rendered number — `0000041` — or null while the document is a draft.
         *
         * **PERSISTED, not recomputed**, so re-reading an issued document returns byte-identically the same string
         * forever, whatever the tenant's number-pattern setting later becomes. A client may print this verbatim.
         */
        public ?string $number,
        /**
         * The raw sequence, or null for a draft. This is what IDENTIFIES the document in its sequence: ordering,
         * uniqueness and the gapless audit trail are built on it, while `number` is what a human reads.
         */
        public ?int $sequence,
        /** @var list<InvoiceLineResource> in persisted position order */
        public array $lines,
        /** @var list<FixedChargeResource> in persisted position order */
        public array $fixedCharges,
        public InvoiceTotalsResource $totals,
    ) {}
}

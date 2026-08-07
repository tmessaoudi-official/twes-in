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
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
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
            // `read: false` because the processor's handler must do the lookup INSIDE its own transaction, for two
            // reasons that are both about correctness rather than efficiency.
            //
            // The lookup is a LOCKING read (`InvoiceRepository::findForMutation()`), and the lock it takes has to be
            // the one held while the number is allocated and the document written — a read API Platform performed
            // beforehand would be a separate transaction whose lock was already released, so two concurrent issues
            // would each act on a stale draft and one of their numbers would end up allocated to no document.
            //
            // And the tenant is bound to the connection with `set_config(..., true)`, TRANSACTION-LOCAL, so a query
            // issued outside a transaction is issued UNBOUND: the canonical policy compares against NULL and the
            // document is invisible even to the tenant that owns it.
            //
            // **This comment previously said the counter's `SELECT … FOR UPDATE` was what made the read's placement
            // matter.** That statement outlived the code: the counter is now one atomic
            // `INSERT … ON CONFLICT DO UPDATE … RETURNING`, with no `FOR UPDATE` anywhere in it, precisely so that
            // serialisation is a property of the statement rather than of a lock somebody must remember to take.
            read: false,
            // 200, not the `Post` default of 201: this creates nothing. It transitions a document that exists, and
            // the response is that same document at its new state.
            status: 200,
            // EVERY ANSWER A CALLER CAN ACT ON IS DECLARED, because two of them were not and a generated client
            // is written against the specification rather than against the processor. `IssueInvoiceProcessor` answers
            // 404 for a document that is absent OR belongs to another tenant (indistinguishably, which is the design
            // of row-level security) and 422 for a domain refusal — issuing a document that is not a draft, or one
            // with no lines. Neither appeared in the schema, so a client generated from it had no branch for the two
            // outcomes a caller is most likely to hit: a double-click and a stale page.
            //
            // **This said "EVERY ANSWER THIS OPERATION CAN GIVE" and that was one word too strong**, corrected in
            // place per `CLAUDE.md` § Gotchas 2026-07-29. A 500 is also reachable and is deliberately absent from the
            // list below: `CLAUDE.md` § "Translation keys" rules that our own faults — a number from the wrong
            // sequence, a `\LogicException` of any kind — map to `error.internal` and carry their detail in the log
            // rather than in a response. That is not an outcome a client branches on, it is the absence of one, and
            // declaring it per-operation would invite exactly the client-side handling the ruling exists to prevent.
            // The distinction the corrected wording draws is the one that matters: 404 and 422 are answers a caller
            // can DO something about, and 500 is a promise that we will.
            //
            // Descriptions rather than content schemas: the 422 body is Symfony's RFC 9457 problem detail, which
            // API Platform already documents globally, and restating its shape here is a second copy that would
            // drift. What was missing is that these statuses EXIST.
            openapi: new OpenApiOperation(
                responses: [
                    '200' => new OpenApiResponse(description: 'The document, now issued, carrying its number.'),
                    '404' => new OpenApiResponse(
                        description: 'No such invoice — absent, or belonging to another tenant. The two are '
                            . 'deliberately indistinguishable.',
                    ),
                    '422' => new OpenApiResponse(
                        description: 'The document cannot be issued: it is not a draft (a second issue of the same '
                            . 'document lands here), or it has no lines.',
                    ),
                ],
            ),
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

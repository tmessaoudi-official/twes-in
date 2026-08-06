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
use Twes\UI\Http\State\InvoiceProvider;

/**
 * An invoice on the wire. **Read-only in this wave, and the omissions are the design.**
 *
 * The first TENANT-SCOPED resource, which is what makes it different from `CurrencyResource`: every read goes
 * through `InvoiceRepository`, which refuses to hydrate an aggregate with no tenant bound, and every row it can
 * see is filtered by PostgreSQL row-level security. A request that proves no tenant gets nothing — not an error
 * naming the document, which would itself be a leak.
 *
 * **NO `Post`, `Put`, `Patch` OR `Delete`, and none of those is an oversight:**
 * - **No `Delete`.** An issued document is never deleted; it is `cancel()`ed, which is a state transition the
 *   aggregate models behind a guard. A delete endpoint would put a way to destroy a legal document beside the way
 *   to void one — and `InvoiceRepository` deliberately declares no `delete()` for the same reason.
 * - **No `Put`/`Patch`.** An issued document is immutable, and the aggregate refuses to mutate once issued. A
 *   partial-update endpoint would be a way to ask for something the domain must refuse, so the refusal belongs in
 *   the route table rather than in an error response.
 * - **No `Post` YET.** Creating an invoice needs an input DTO, validation at the edge, the gapless number
 *   allocator and a transaction the caller owns — `InvoiceRepository::save()` refuses outside one, deliberately,
 *   because a number and the document carrying it must commit together. That is its own deliverable; this read
 *   path establishes the shape it will reuse.
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

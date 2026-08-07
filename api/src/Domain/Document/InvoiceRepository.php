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
 * Storing and retrieving an `Invoice`. A PORT — the adapter is in `Infrastructure/`.
 *
 * Beside the aggregate it serves, per `CLAUDE.md` § Architecture: *"A repository interface belongs beside the
 * aggregate it serves, not beside its Doctrine implementation."*
 *
 * **THERE IS NO TENANT PARAMETER, AND THAT CORRECTS A RECORDED RULING.** `DocumentIdentity`'s docblock says *"The
 * repository takes the tenant as an explicit argument instead, which is also what enforces the boundary rule that no
 * tenant-less path may hydrate an aggregate."* That is not available: `TenantId` lives in `Infrastructure/Tenancy/`,
 * and a `Domain/` interface naming it is an outward dependency — a **P0**, and `scripts/gates/layer-dependencies.php`
 * refuses it. [Verified: a probe interface here declaring `f(TenantId $t)` produced *"Domain references
 * Twes\Infrastructure\Tenancy\TenantId, which is outward"*, exit 1.] Passing the id as a bare `string` instead would
 * satisfy the gate and lose the point: a `string` parameter is not a tenancy control, it is a hole with a type.
 *
 * **So the boundary rule is enforced in the ADAPTER, which is stronger rather than weaker**, for the same reason the
 * savepoint re-check lives in a DBAL middleware instead of in each repository. A parameter is satisfied by whatever
 * tenant id the caller happens to hold — including one dug out of a request, or the wrong one — whereas an adapter
 * constructed with the request's `TenantContext` cannot be handed a different tenant at the call site. And § Gotchas
 * 2026-07-31 already settled the general form of this: tenancy is AMBIENT CONTEXT, not a field, and *"a field every
 * type needs is not a field"*. A tenant parameter on every repository method is that same mistake one level up — it
 * would be needed by `save()`, by `find()`, and by every query method this interface ever grows.
 *
 * **WHAT IT DOES NOT DO, deliberately:**
 *
 * - **No `flush()`, and no transaction of its own.** The adapter REFUSES to save outside an active transaction
 *   rather than opening one. A document number is gapless (§ Gotchas 2026-07-31: `nextval()` is forbidden precisely
 *   because it does not roll back), so allocating a number and persisting the document that carries it must be one
 *   unit of work. A repository that committed on its own behalf would make the atomic case impossible to write,
 *   and the failure would be a permanent hole in an invoice sequence — what a tax authority reads as a suppressed
 *   sale.
 * - **No `delete()`.** An issued document is never deleted; it is `cancel()`ed, which is a state transition the
 *   aggregate already models behind a guard. Adding a delete would put a way to destroy a legal document next to
 *   the way to void one. A draft has no such argument, but it has no need either until something asks for it.
 * - **No query methods.** Listing, filtering and pagination belong to a read model and to API Platform's own
 *   pagination extension (`CLAUDE.md` § "The Symfony ecosystem is the ONLY vocabulary"), not to an aggregate
 *   repository, which exists to load and store ONE consistency boundary at a time.
 */
interface InvoiceRepository
{
    /**
     * Store the aggregate whole, under the given identity.
     *
     * WHOLE, not incrementally: the aggregate is `final readonly` and its mutators return new instances, so there is
     * no dirty-tracking for an adapter to do and no partial state for it to be in. Lines and charges are replaced
     * rather than reconciled.
     *
     * IDEMPOTENT on the identity: saving twice with the same `$identity` stores the second state, it does not create
     * a second document.
     *
     * @throws \RuntimeException if there is no active transaction, or no current tenant
     */
    public function save(DocumentIdentity $identity, Invoice $invoice): void;

    /**
     * Load the aggregate stored under `$id`, or null when the current tenant has no such document.
     *
     * **NULL RATHER THAN AN EXCEPTION, and the distinction is a tenancy one.** Under row-level security a document
     * belonging to ANOTHER tenant is indistinguishable from one that does not exist — that is the whole design — so
     * "not found" is the only honest answer and a "wrong tenant" error could not be produced without defeating the
     * isolation that makes it unnecessary. The transport layer turns null into `error.not_found`.
     *
     * @param string $id a canonical lowercase-hyphenated UUID; see {@see DocumentIdentity} for why the id is a
     *                   string in this layer
     *
     * @throws \RuntimeException if there is no current tenant
     * @throws \InvalidArgumentException if `$id` is not a canonical UUID — an ill-formed id must not reach a query
     */
    public function find(string $id): ?PersistedInvoice;

    /**
     * The same lookup, plus a guarantee: **no other transaction may mutate this document until mine ends.**
     *
     * **THIS EXISTS BECAUSE `find()` DOES NOT PROVIDE THAT, AND AN ISSUE PERFORMED ON A STALE READ BURNS A DOCUMENT
     * NUMBER.** Two concurrent issues of one draft, with an ordinary read: both see `draft`, the counter serialises
     * the allocations so they take 1 and 2, both build an issued aggregate from their own stale snapshot, and the
     * second `save()` overwrites the first. The document ends up numbered 2, **number 1 is allocated to no document
     * at all**, and the client that already received a 200 for invoice 1 finds it renumbered. [Verified against the
     * migrated schema with two live transactions: `allocated=[1,2] on documents=[2]` — 1 orphaned. With this
     * guarantee: `allocated=[1] on documents=[1]`.] A hole in an invoice sequence is what a tax authority reads as a
     * suppressed sale, which is why § Gotchas 2026-07-31 forbids `nextval()` in the first place; reaching the same
     * hole through a stale read would have made that whole decision worthless.
     *
     * **STATED AS A GUARANTEE, NOT AS A LOCK, and that is not squeamishness about naming.** `Domain/` must be able
     * to express this for an adapter that is not PostgreSQL and possibly not SQL — a database-per-tenant deployment,
     * an event store, a test double. What every one of them owes is serialisability over one document, and how they
     * get there is theirs. The Postgres adapter uses `SELECT … FOR UPDATE`.
     *
     * **CALL IT BEFORE ALLOCATING ANYTHING.** The guarantee is only worth having if it is acquired before the first
     * statement that can block on something else: the counter row is itself contended, so a caller that allocated
     * first would already have taken the number it is trying not to waste. Acquiring the document first also fixes
     * ONE lock order for every writer — document, then counter — which is what makes a deadlock between two issues
     * impossible rather than unlikely.
     *
     * A separate method rather than a flag on `find()`: a read path must NOT take this. `GET /api/invoices/{id}`
     * runs on the same rows, and a plain fetch that quietly took a write lock would serialise every reader behind
     * every writer for no reason a caller could see.
     *
     * @param string $id a canonical lowercase-hyphenated UUID, exactly as {@see self::find()} takes
     *
     * @throws \RuntimeException if there is no current tenant, or no active transaction — a guarantee that outlives
     *                           no transaction is not a guarantee, so this refuses rather than returning a document
     *                           it cannot protect
     * @throws \InvalidArgumentException if `$id` is not a canonical UUID
     */
    public function findForMutation(string $id): ?PersistedInvoice;
}

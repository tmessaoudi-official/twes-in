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
}

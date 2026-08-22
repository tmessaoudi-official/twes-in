<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Product;

/**
 * The port for storing and fetching a {@see Product}.
 *
 * Beside the aggregate it serves, per `CLAUDE.md` § Architecture: *"A repository interface belongs beside the
 * aggregate it serves, not beside its Doctrine implementation."*
 *
 * **NO TENANT PARAMETER, and its absence is an invariant rather than an omission.** `TenantId` lives in
 * `Infrastructure/Tenancy/`, so a `Domain/` interface naming it would be an outward dependency and a P0 that
 * `scripts/gates/layer-dependencies.php` refuses — `CLAUDE.md` § Gotchas 2026-08-06 records that being discovered
 * with a probe interface. The deeper reason survives the gate: a parameter is satisfied by whatever tenant id the
 * caller happens to hold, INCLUDING THE WRONG ONE, while a context resolved once per request cannot be forged at
 * the call site. And the reductio applies here as it did to `ClientRepository` — if `find()` needs one, so does
 * every method this interface ever grows, and something every method needs is context rather than an argument.
 */
interface ProductRepository
{
    /**
     * Store a product, creating it or replacing what is stored.
     *
     * **AN UNGUARDED UPSERT, unlike the invoice repository's, and the difference is what the record MEANS.** An
     * issued invoice is a legal document a client already holds, so its write-once number is enforced by the
     * statement itself. A product is a catalogue entry that is MEANT to be edited — a cost changes, a supplier
     * raises a price, a name is corrected — and an already-issued document is protected from those edits by
     * value rather than by a guard here: `DocumentLine` carries a `Money` and a `Rate`, never a product id, so
     * there is nothing for a later edit to reach.
     *
     * The implementation must refuse when no tenant is bound and when there is no active transaction. Both are
     * fail-closed: under row-level security an unbound write is refused by the policy, and the tenant binding is
     * transaction-local, so outside a transaction there is no binding to be refused by.
     *
     * @throws \RuntimeException if no tenant is bound, or there is no active transaction
     */
    public function save(Product $product): void;

    /**
     * One product belonging to the current tenant, or `null`.
     *
     * **`null` COVERS BOTH "does not exist" AND "belongs to another tenant", indistinguishably.** That sameness
     * is the design of row-level security rather than a limitation of it: an answer that distinguished them would
     * confirm a product's existence to a tenant not entitled to know.
     *
     * @throws \InvalidArgumentException if the id is not a canonical UUID — refused here rather than passed to
     *                                   the database, which would raise a type error and turn a missing product
     *                                   into a 500
     * @throws \RuntimeException if no tenant is bound, or there is no active transaction
     */
    public function find(string $id): ?Product;
}

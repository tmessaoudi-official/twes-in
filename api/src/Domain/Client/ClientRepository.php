<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Client;

/**
 * How a {@see Client} is stored and found.
 *
 * Beside the aggregate it serves, per `CLAUDE.md` § Architecture: *"A repository interface belongs beside the
 * aggregate it serves, not beside its Doctrine implementation."*
 *
 * **NO TENANT PARAMETER, AND THAT IS STRONGER THAN ONE RATHER THAN WEAKER.** `TenantId` lives in
 * `Infrastructure/Tenancy/`, so a `Domain/` interface naming it is an outward dependency and a **P0** that
 * `scripts/gates/layer-dependencies.php` refuses — `DocumentIdentity`'s docblock records the same rule being
 * discovered the hard way, with a probe interface producing *"Domain references
 * Twes\Infrastructure\Tenancy\TenantId, which is outward"*. What the adapter does instead is take the request's
 * `TenantContext` in its constructor and refuse when none is bound: a PARAMETER is satisfied by whatever tenant
 * id the caller happens to hold, INCLUDING THE WRONG ONE, while a context resolved once cannot be forged at the
 * call site. The reductio recorded in `CLAUDE.md` § Gotchas 2026-07-31 applies to a parameter on every method
 * exactly as it applies to a field on every type: if `find()` needs it, so do `save()` and every query method
 * this interface ever grows, and something every method needs is context rather than an argument.
 *
 * **BOTH METHODS REQUIRE AN OPEN TRANSACTION, AND FOR THE READ THAT IS THE POINT RATHER THAN SYMMETRY.** The
 * tenant binding row-level security compares against is written by `TenantBindingMiddleware` on
 * `beginTransaction()` and is TRANSACTION-LOCAL (`set_config(…, true)`). Outside a transaction the connection
 * is bound to no tenant, an unbound session sees NOTHING, and a read would return "no such client" for a
 * client that exists — a fail-closed tenancy refusal silently downgraded into a wrong answer, with every
 * fixture still green. `CLAUDE.md` § Gotchas 2026-08-07 records that exact failure costing three commits, and
 * it is why `InvoiceProvider` wraps a pure read in a transaction: that reads like ceremony and is not.
 */
interface ClientRepository
{
    /**
     * Store a client, creating it or replacing it.
     *
     * **NO WRITE-ONCE PREDICATE, unlike `InvoiceRepository::save()`.** That one carries
     * `WHERE number IS NULL OR number = EXCLUDED.number` because a document number is write-once on a legal
     * document a client already holds. A client record is *meant* to be edited — an address changes, a contact
     * leaves — so the only invariant is one row per (tenant, id), which the primary key already enforces.
     *
     * @throws \RuntimeException if there is no active transaction, or no current tenant
     */
    public function save(Client $client): void;

    /**
     * The client with this id, or `null` if this tenant has no such client.
     *
     * **`null` MEANS "NOT YOURS OR NOT THERE", AND THE TWO ARE DELIBERATELY INDISTINGUISHABLE.** Another
     * tenant's client id must not be answerable — telling a caller "that exists but is not yours" is an
     * existence oracle over every tenant's client list, which is the class of leak
     * `scripts/gates/schema-tenancy.php`'s key axis exists to prevent at the schema level.
     *
     * @throws \RuntimeException if there is no current tenant, or no active transaction
     * @throws \InvalidArgumentException if `$id` is not a canonical UUID — an ill-formed id must not reach a
     *                                   query, where PostgreSQL would raise `invalid input syntax for type
     *                                   uuid` and turn what should be a 404 into a 500
     */
    public function find(string $id): ?Client;
}

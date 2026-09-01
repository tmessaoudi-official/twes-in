<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Tenancy;

/**
 * *How* one tenant's data is kept away from another's.
 *
 * Two modes are planned, chosen by configuration (`TWES_TENANCY_MODE`), and this interface is the seam
 * that makes the choice invisible to every caller:
 *
 *   - **shared** — one database for everyone, every tenant-owned row carrying its tenant, and the
 *     database itself refusing to return another tenant's rows. Implemented:
 *     {@see PostgresRowLevelSecurityIsolation}.
 *   - **database** — one database per tenant, isolation by virtue of the connection. Not yet
 *     implemented; the seam exists so that adding it is an adapter rather than a rewrite. What it will
 *     cost is recorded in docs/SPEC.md: migrations run per tenant,
 *     provisioning becomes a workflow, and cross-tenant reporting needs fan-out.
 *
 * The parameter is a PDO connection because Wave 0 has no ORM yet. When Doctrine lands this becomes
 * its `Connection`; both are Infrastructure types, so that change crosses no layer boundary.
 */
interface TenantIsolationStrategy
{
    /**
     * Bind a database session to the current tenant. Must run before any query on that session.
     *
     * Implementations are expected to **fail closed**: if isolation cannot be established, no data may
     * be readable. Returning quietly and leaving a session unscoped is the failure mode this whole
     * interface exists to prevent.
     */
    public function bind(\PDO $connection, TenantContext $context): void;

    /**
     * Re-read the connection's actual binding and refuse to continue if it no longer matches the context.
     *
     * **ON THE PORT, not only on the implementation, and that is a Wave 1 decision rather than a tidy-up**
     * (`build-waves.plan.md`: *"a repository injected with the port — which is the point of the seam — cannot
     * call it today without an `instanceof`, so correct code written against the abstraction is defenceless"*).
     * A guard reachable only by narrowing the abstraction is a guard the abstraction's users will not call.
     *
     * WHY IT IS NEEDED AT ALL: {@see PostgresRowLevelSecurityIsolation::bind()} writes the tenant
     * TRANSACTION-LOCALLY, because a session-scoped value would leak to whoever gets the pooled connection next.
     * `ROLLBACK TO SAVEPOINT` restores transaction-local settings to their value at the savepoint, so a bind made
     * inside a savepoint is silently undone while the PHP-side context still believes the new tenant — and every
     * subsequent statement is scoped to the PREVIOUS tenant under the new tenant's name. Reproduced on a real
     * connection with no ORM at all; see `SavepointBindingDivergenceTest`.
     *
     * **The `database`-per-tenant implementation will satisfy this trivially** — there is no binding to diverge
     * when the tenant IS the connection — and that is the correct shape for a port: the obligation is stated once,
     * and an adapter for which it cannot fail says so in three lines rather than the caller learning which
     * adapters need it.
     *
     * Implementations must FAIL rather than re-bind. Re-binding is worse than useless here: `bind()` refuses while
     * the reverted value is present, and the only way past that refusal is the empty-string masking
     * {@see PostgresRowLevelSecurityIsolation} documents as a bypass. The unit of work has to be abandoned.
     *
     * A TENANT-LESS CONTEXT IS NOT AN ERROR, and implementations must not throw for one: genuinely cross-tenant
     * work exists (installation, a global health check, a cross-tenant migration), and the divergence in that
     * direction — the context holding nothing while the connection is still scoped to a tenant — is the more
     * dangerous half, because a cross-tenant report then returns one tenant's rows as the whole set.
     *
     * @throws \RuntimeException if the connection's binding and the context disagree
     */
    public function assertStillBoundTo(\PDO $connection, TenantContext $context): void;
}

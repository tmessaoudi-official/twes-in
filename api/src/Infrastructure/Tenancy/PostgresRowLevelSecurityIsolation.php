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

use Twes\Infrastructure\Tenancy\Exception\NoCurrentTenant;

/**
 * Shared-database isolation, enforced by PostgreSQL itself through row-level security.
 *
 * **Why the database and not the ORM.** The plan called for a default-on Doctrine filter, and the
 * requirement behind it was that forgetting the filter must be impossible. A filter cannot quite
 * deliver that: it scopes queries the ORM builds, and a raw DQL fragment, a native query, a migration,
 * a reporting job or a `psql` session all bypass it. An RLS policy is applied by the server to every
 * statement on the table, whatever issued it. So the policy is the primary guard here, and a Doctrine
 * filter — when Doctrine lands — becomes a second layer that also keeps the SQL readable, rather than
 * the only thing standing between two tenants.
 *
 * **How it works.** Each tenant-owned table carries a policy comparing its tenant column against a
 * session variable, and this class sets that variable. `current_setting(..., true)` returns NULL when
 * unset, so `company_id = NULL` is NULL, so no row qualifies: an unbound session sees **nothing**
 * rather than everything. That is the fail-closed property the whole design rests on, and
 * TenantIsolationTest asserts it directly.
 *
 * **Three things that will silently defeat this, all of them checkable:**
 *
 *  1. **A superuser connection.** RLS never applies to superusers. The application role must not be
 *     one, and `assertConnectionCannotBypassPolicies()` exists to prove it.
 *  2. **The table's owner.** RLS is skipped for the owner unless the table also has
 *     `FORCE ROW LEVEL SECURITY`. Every migration that enables RLS must also force it.
 *  3. **A pooled connection.** `SET LOCAL` is scoped to the transaction, which is deliberate: a
 *     transaction-scoped setting cannot leak to whoever gets the connection next. A session-scoped
 *     `SET` would, and is why this class does not use one.
 */
final readonly class PostgresRowLevelSecurityIsolation implements TenantIsolationStrategy
{
    /**
     * The session variable the RLS policies read.
     *
     * A custom GUC needs a prefixed name; changing it means changing every policy, so it is defined
     * once here and referenced by the migrations that create them.
     */
    public const string TENANT_SETTING = 'twes.tenant_id';

    /**
     * @throws NoCurrentTenant if no tenant is bound — binding "no tenant" is refused rather than
     *                         leaving the session unscoped
     * @throws \RuntimeException if not inside a transaction, where SET LOCAL would have no effect
     */
    public function bind(\PDO $connection, TenantContext $context): void
    {
        if (!$context->hasTenant()) {
            throw NoCurrentTenant::create();
        }

        if (!$connection->inTransaction()) {
            // SET LOCAL outside a transaction is silently discarded by PostgreSQL (with a warning),
            // which would leave the session unscoped while this method appeared to succeed. Refusing
            // is the fail-closed behaviour the interface demands.
            throw new \RuntimeException(
                'Tenant isolation must be bound inside a transaction: SET LOCAL has no effect outside '
                . 'one, so the session would be left unscoped. Begin the transaction first.',
            );
        }

        // set_config() rather than a literal SET LOCAL, because SET does not accept bound parameters
        // and interpolating a tenant id into SQL would put an identifier from the request into a
        // statement — the exact shape of an injection. The third argument makes it transaction-local.
        $statement = $connection->prepare('SELECT set_config(?, ?, true)');
        $statement->execute([self::TENANT_SETTING, $context->tenantId()->toString()]);
    }

    /**
     * Prove that this connection's role is actually subject to row-level security.
     *
     * Isolation that is silently bypassed is worse than none, because everything looks correct. A
     * superuser or a `BYPASSRLS` role sees every tenant while every policy remains in place and every
     * test that only checks the happy path still passes. Call this at boot, and in CI.
     *
     * @throws \RuntimeException if the role can bypass policies
     */
    public function assertConnectionCannotBypassPolicies(\PDO $connection): void
    {
        $statement = $connection->query(
            'SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user',
        );

        if (false === $statement) {
            throw new \RuntimeException('Could not inspect the current database role.');
        }

        /** @var array{rolsuper: bool|string, rolbypassrls: bool|string}|false $role */
        $role = $statement->fetch(\PDO::FETCH_ASSOC);

        if (false === $role) {
            throw new \RuntimeException('The current database role could not be found in pg_roles.');
        }

        if (self::isTrue($role['rolsuper']) || self::isTrue($role['rolbypassrls'])) {
            throw new \RuntimeException(
                'The application database role is a superuser or has BYPASSRLS, so row-level security '
                . 'does not apply to it and every tenant is visible to every other. Connect as a '
                . 'restricted role.',
            );
        }
    }

    /** PDO reports booleans as PHP bools or as "t"/"f" depending on the driver build. */
    private static function isTrue(bool|string $value): bool
    {
        return true === $value || 't' === $value || '1' === $value;
    }
}

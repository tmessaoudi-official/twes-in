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
 * session variable, and this class sets that variable. Build the policy with {@see self::policySqlFor()}
 * rather than by hand — the exact expression matters, for a reason that is not obvious:
 *
 * `current_setting('twes.tenant_id', true)` returns NULL only on a connection that has **never** bound.
 * After the first `set_config`, PostgreSQL has created the custom-GUC placeholder, and its reset value
 * is the **empty string, not NULL**. So on any pooled or reused connection a naive policy of
 * `company_id = current_setting(...)::uuid` hits `''::uuid` and raises SQLSTATE 22P02 instead of
 * returning zero rows — turning "sees nothing" into "errors", including for the legitimately tenant-less
 * callers TenantContext documents. Wrapping it in `nullif(..., '')` collapses both the never-set and the
 * reset-to-empty states to NULL, so `company_id = NULL` is NULL and no row qualifies in **either**. That
 * is the fail-closed property the whole design rests on, and TenantIsolationTest asserts it on a virgin
 * connection *and* on a reused one — the second case is the one that matters in production and the one
 * an earlier version of this class got wrong.
 *
 * **Three things that will silently defeat this, all of them checkable:**
 *
 *  0. **The application role owning the tables.** `FORCE ROW LEVEL SECURITY` stops an owner *skipping*
 *     policies; it does nothing about an owner *removing* them. A table owner can
 *     `ALTER TABLE ... DISABLE ROW LEVEL SECURITY`, or add `CREATE POLICY ... USING (true)`, in one
 *     statement — and `TRUNCATE` is never subject to row security at any privilege level. So the runtime
 *     role must **not own** the tenant-owned tables and must not hold `TRUNCATE` on them. Migrations run
 *     as a separate, owning role. This is an infrastructure requirement, recorded in infra/README.md.
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

        $expected = $context->tenantId()->toString();

        // set_config() rather than a literal SET LOCAL, because SET does not accept bound parameters
        // and interpolating a tenant id into SQL would put an identifier from the request into a
        // statement — the exact shape of an injection. The third argument makes it transaction-local,
        // which is what stops a binding surviving into whoever gets this connection next.
        $statement = $connection->prepare('SELECT set_config(?, ?, true)');
        $statement->execute([self::TENANT_SETTING, $expected]);

        // Read back rather than trust. set_config returns the value it set, so this costs nothing, and
        // it closes the gap where some other statement on this connection has set the same GUC at
        // SESSION scope: transaction-local writing stops this class leaking its own value, but nothing
        // stops another writer, and a silently mis-scoped session is the one failure that leaks data
        // while every test passes.
        $actual = $statement->fetchColumn();

        if ($actual !== $expected) {
            throw new \RuntimeException(\sprintf(
                'Tenant isolation did not take effect: expected the %s session setting to be "%s" but '
                . 'it reads "%s". Refusing to continue on an unscoped connection.',
                self::TENANT_SETTING,
                $expected,
                \is_string($actual) ? $actual : get_debug_type($actual),
            ));
        }
    }

    /**
     * The canonical policy SQL for a tenant-owned table.
     *
     * Emitted from one place so that no migration can get the expression subtly wrong — and the
     * `nullif` is exactly the kind of subtlety that would be dropped by hand. All three statements are
     * required and all three are returned together: `ENABLE` without `FORCE` leaves the owner exempt,
     * and a policy without either is inert.
     *
     * Two schema requirements this cannot enforce, which every tenant-owned migration must honour and
     * which no gate checks yet:
     *
     *  - **Composite keys.** PostgreSQL performs referential-integrity checks with row security
     *    BYPASSED. With a single-column foreign key, a session bound to tenant A can attach its own
     *    child row to tenant B's *invisible* parent; tenant B then deletes its own parent and takes
     *    A's row with it — or, with `NO ACTION`, is blocked from deleting its own row and told why. So
     *    tenant-owned tables use `PRIMARY KEY (company_id, id)` and foreign keys on **both** columns.
     *  - **Composite unique constraints.** A unique check also bypasses RLS, so a bare
     *    `UNIQUE (invoice_number)` is an existence oracle for another tenant's invoice numbers.
     *    Every unique constraint on tenant-owned data includes the tenant column.
     *
     * @return list<string> statements to run in order, in a migration
     */
    public static function policySqlFor(string $table, string $tenantColumn = 'company_id'): array
    {
        $scoped = \sprintf(
            '%s = nullif(current_setting(%s, true), \'\')::uuid',
            $tenantColumn,
            \sprintf("'%s'", self::TENANT_SETTING),
        );

        return [
            \sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $table),
            \sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $table),
            \sprintf(
                'CREATE POLICY tenant_isolation ON %s USING (%s) WITH CHECK (%s)',
                $table,
                $scoped,
                $scoped,
            ),
        ];
    }

    /**
     * Whether a role's catalogue attributes let it bypass row-level security.
     *
     * A pure function taking the `pg_roles` row, so it can be unit-tested — the throwing branch of
     * {@see self::assertConnectionCannotBypassPolicies()} otherwise has no coverage, because creating a
     * `BYPASSRLS` role requires the very privilege the application role must not have.
     *
     * @param array{rolsuper: bool|string, rolbypassrls: bool|string} $role
     */
    public static function roleCanBypassPolicies(array $role): bool
    {
        return self::isTrue($role['rolsuper']) || self::isTrue($role['rolbypassrls']);
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

        if (self::roleCanBypassPolicies($role)) {
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

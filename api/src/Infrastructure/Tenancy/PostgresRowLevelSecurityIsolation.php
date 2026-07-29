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
 *  4. **A tenant id pinned on the connection itself.** A DSN may carry
 *     `options='-c twes.tenant_id=…'`, which needs no privilege and is exactly the shape a `DATABASE_URL`
 *     takes. Because `bind()` writes transaction-locally, PostgreSQL restores that session value on
 *     COMMIT — so every later unbound statement reads and writes that tenant, and the fail-closed
 *     property is gone. `bind()`'s read-back cannot see it, because it only runs when `bind()` runs. It is
 *     checked at connection acquisition by {@see self::assertNoTenantPinnedOnTheConnection()}.
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

        // BEFORE writing, on every bind — not once at acquisition. A single session-scope `set_config(...,
        // false)` after acquisition reopens the bypass completely, with bind()'s read-back still reporting
        // success, because a transaction-local write shadows the pin until COMMIT restores it. Checking
        // here covers the DSN pin, PGOPTIONS, and any later writer, at the cost of one cheap query.
        //
        // Read outside this transaction's own write: PostgreSQL restores the session value on COMMIT, so
        // what matters is what the session held when this transaction began.
        self::assertSessionTenantIsUnset($connection);

        $expected = $context->tenantId()->toString();

        // set_config() rather than a literal SET LOCAL, because SET does not accept bound parameters
        // and interpolating a tenant id into SQL would put an identifier from the request into a
        // statement — the exact shape of an injection. The third argument makes it transaction-local,
        // which is what stops a binding surviving into whoever gets this connection next.
        $statement = $connection->prepare('SELECT set_config(?, ?, true)');
        $statement->execute([self::TENANT_SETTING, $expected]);

        // Read back rather than trust: set_config returns the value it set, so this costs nothing and it
        // catches a binding that did not take.
        //
        // What it does NOT do — corrected after this comment overclaimed: it cannot detect a value set at
        // SESSION scope by anything else, because it only runs when bind() runs and the session value is
        // restored on COMMIT. That gap is closed at connection acquisition instead, by check 4 above.
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
        // EVERY REACHABLE ROLE, not just current_user. `rolsuper` and `rolbypassrls` are not inherited, so
        // a role that is a *member* of a superuser, BYPASSRLS or table-owning role reads f/f in its own
        // pg_roles row, passes a naive check, and then reaches those privileges with one `SET ROLE`. That
        // precondition is not exotic: infra/README.md mandates a separate owning migration role, and the
        // ordinary way to wire that in one container is to grant it to the runtime role.
        $statement = $connection->query(
            'SELECT bool_or(rolsuper) AS rolsuper, bool_or(rolbypassrls) AS rolbypassrls '
            . 'FROM pg_roles WHERE pg_has_role(current_user, oid, \'MEMBER\')',
        );

        if (false === $statement) {
            throw new \RuntimeException('Could not inspect the current database role.');
        }

        /** @var array{rolsuper: bool|string, rolbypassrls: bool|string}|false $role */
        $role = $statement->fetch(\PDO::FETCH_ASSOC);

        if (false === $role) {
            throw new \RuntimeException('The current database role could not be found in pg_roles.');
        }

        // bool_or over an empty set is NULL, which would read as "safe". current_user is always a member of
        // itself, so an empty set means the query is wrong rather than the role being unprivileged.
        if (null === $role['rolsuper'] && null === $role['rolbypassrls']) {
            throw new \RuntimeException(
                'Could not determine the privileges reachable from the current role. Refusing rather than '
                . 'assuming they are safe.',
            );
        }

        if (self::roleCanBypassPolicies($role)) {
            throw new \RuntimeException(
                'A role reachable from this connection is a superuser or has BYPASSRLS, so row-level '
                . 'security can be escaped with one SET ROLE and every tenant becomes visible to every '
                . 'other. Connect as a restricted role that is a member of no privileged role — note that '
                . 'granting the table-owning migration role to the runtime role is enough to fail this.',
            );
        }

        self::assertNoTenantPinnedOnTheConnection($connection);
    }

    /**
     * Refuse a connection that already carries a tenant id.
     *
     * The fourth bypass, and the least obvious: a DSN can pin the GUC with `options='-c twes.tenant_id=…'`
     * at no privilege. Since `bind()` writes transaction-locally, that value is restored on every COMMIT,
     * so the unbound path silently becomes *scoped to whoever pinned it* rather than scoped to nothing.
     *
     * Two values are acceptable and both mean "no tenant": **NULL** on a connection that has never bound,
     * and the **empty string** after one has — a custom GUC's reset value is `''`, not NULL, the same
     * asymmetry the policy's `nullif` exists to absorb. Treating `''` as pinned would reject every
     * recycled connection in production. Anything else was put there by something that is not this class.
     *
     * @throws \RuntimeException if a tenant id is already present
     */
    public static function assertNoTenantPinnedOnTheConnection(\PDO $connection): void
    {
        // OUTSIDE a transaction only, and this is not pedantry. Inside one, `''` may be a transaction-local
        // shadow over a live session pin that returns on COMMIT — so the check would accept a connection it
        // should refuse. It would also throw on a correctly *bound* connection, blaming a DSN option for a
        // legitimate binding. Both directions are wrong, so the state is refused rather than interpreted.
        if ($connection->inTransaction()) {
            throw new \RuntimeException(
                'Check for a pinned tenant OUTSIDE a transaction. Inside one the session value may be '
                . 'shadowed by a transaction-local write, so neither answer means what it appears to.',
            );
        }

        $statement = $connection->prepare('SELECT current_setting(?, true)');
        $statement->execute([self::TENANT_SETTING]);

        $pinned = $statement->fetchColumn();

        if (null === $pinned || false === $pinned || '' === $pinned) {
            return;
        }

        throw new \RuntimeException(\sprintf(
            'This connection already carries %s = "%s", so on every COMMIT PostgreSQL restores it and '
            . 'unbound statements silently read that tenant instead of nothing. A DSN option such as '
            . "options='-c %s=…' does this at no privilege. Remove it from the connection string.",
            self::TENANT_SETTING,
            \is_string($pinned) ? $pinned : get_debug_type($pinned),
            self::TENANT_SETTING,
        ));
    }

    /**
     * The in-transaction form of the pinned-tenant check, for use from `bind()`.
     *
     * `bind()` necessarily runs inside a transaction, where the public check refuses to answer. What is
     * checked here is narrower and sufficient: at the moment `bind()` is called it has not yet written, so
     * a non-empty value can only have come from somewhere else — a DSN option, `PGOPTIONS`, or a
     * session-scope write by other code. `''` and NULL are both "no tenant", exactly as above.
     *
     * @throws \RuntimeException if a tenant id is already present
     */
    private static function assertSessionTenantIsUnset(\PDO $connection): void
    {
        $statement = $connection->prepare('SELECT current_setting(?, true)');
        $statement->execute([self::TENANT_SETTING]);

        $existing = $statement->fetchColumn();

        if (null === $existing || false === $existing || '' === $existing) {
            return;
        }

        throw new \RuntimeException(\sprintf(
            'Refusing to bind: %s already reads "%s" before this binding was written, so something else on '
            . 'this connection set it — a DSN option, PGOPTIONS, or a session-scope write. On COMMIT that '
            . 'value returns and unbound statements would silently read that tenant.',
            self::TENANT_SETTING,
            \is_string($existing) ? $existing : get_debug_type($existing),
        ));
    }

    /** PDO reports booleans as PHP bools or as "t"/"f" depending on the driver build. */
    private static function isTrue(bool|string $value): bool
    {
        return true === $value || 't' === $value || '1' === $value;
    }
}

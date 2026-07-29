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
 *     as a separate, owning role. This is an infrastructure requirement, recorded in infra/README.md —
 *     and, since a round found this control asserted in prose while the code checked only the two role
 *     attributes below, it is now also checked, by
 *     {@see self::assertPolicedTablesAreBeyondThisRolesReach()}.
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
     * @throws \RuntimeException if not inside a transaction, where SET LOCAL would have no effect; if the
     *                           connection already carries a tenant id; or if this transaction has already
     *                           bound one — rebinding within a transaction is refused, see
     *                           {@see self::assertSessionTenantIsUnset()}
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
        // What this read CANNOT distinguish, stated because an earlier version of this comment claimed it
        // could: from inside a transaction, a value written transaction-locally and a value written at
        // session scope look identical. So a second bind() in the same transaction is refused too — see
        // assertSessionTenantIsUnset() for why that is the right way round.
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
     * @param array{rolsuper: bool|string, rolbypassrls: bool|string, rolreplication?: bool|string} $role
     */
    public static function roleCanBypassPolicies(array $role): bool
    {
        return self::isTrue($role['rolsuper'])
            || self::isTrue($role['rolbypassrls'])
            // REPLICATION is a bypass of a different kind and an equal one: it does not defeat the policy,
            // it goes around the query layer the policy lives in. See the query in
            // assertConnectionCannotBypassPolicies() for the proof.
            || self::isTrue($role['rolreplication'] ?? false);
    }

    /**
     * Prove that this connection's role is actually subject to row-level security.
     *
     * Isolation that is silently bypassed is worse than none, because everything looks correct. A
     * superuser or a `BYPASSRLS` role sees every tenant while every policy remains in place and every
     * test that only checks the happy path still passes. Call this at boot, and in CI.
     *
     * Three separate questions, because a round found this method answering only the first and being
     * named as though it answered all of them: can a reachable role bypass policies *by attribute*, can
     * this role reach *around* the policies on the tables themselves (ownership, `TRUNCATE`), and is a
     * tenant already pinned on the connection.
     *
     * @return int the number of policed tables inspected, so a caller can see the check had subject
     *             matter; zero is refused rather than reported
     *
     * @throws \RuntimeException if the role can bypass policies, reach around them, or arrives pinned
     */
    public function assertConnectionCannotBypassPolicies(\PDO $connection): int
    {
        // EVERY REACHABLE ROLE, and reachable from SESSION_USER — not current_user. Two corrections, both
        // from findings. First: `rolsuper` and `rolbypassrls` are not inherited, so a role that is a
        // *member* of a superuser or BYPASSRLS role reads f/f in its own pg_roles row, passes a naive
        // check, and then reaches those privileges with one `SET ROLE`. Second, subtler: PostgreSQL
        // authorises `SET ROLE` against **session_user**, so on a connection where current_user has
        // already been changed — `options='-c role=…'` in the DSN, or `ALTER ROLE … SET role`, neither
        // needing any application code — a predicate over current_user enumerates a strictly smaller set
        // than the connection can actually reach. Both are unioned: current_user for what is held right
        // now, session_user for what one statement can reach.
        $statement = $connection->query(
            'SELECT bool_or(rolsuper) AS rolsuper, bool_or(rolbypassrls) AS rolbypassrls, '
            // THE THIRD ATTRIBUTE, and it is not a lesser one. REPLICATION grants a full PHYSICAL read of
            // the entire cluster — `pg_basebackup` copies the heap files, and row security is not involved
            // at any point. A role with LOGIN REPLICATION and nothing else has correctly-policed SQL, which
            // is what makes it convincing, and the same credentials walk out with every tenant's data.
            // Round 5 proved it by recovering both tenants' rows out of a base backup taken with a role this
            // check had just certified as "actually subject to row-level security".
            . 'bool_or(rolreplication) AS rolreplication '
            . 'FROM pg_roles WHERE pg_has_role(session_user, oid, \'MEMBER\') '
            . "OR pg_has_role(current_user, oid, 'MEMBER')",
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
        if (null === $role['rolsuper'] && null === $role['rolbypassrls'] && null === $role['rolreplication']) {
            throw new \RuntimeException(
                'Could not determine the privileges reachable from the current role. Refusing rather than '
                . 'assuming they are safe.',
            );
        }

        if (self::roleCanBypassPolicies($role)) {
            throw new \RuntimeException(
                'A role reachable from this connection is a superuser, or has BYPASSRLS, or has '
                . 'REPLICATION. The first two escape row-level security with one SET ROLE; the third goes '
                . 'around it entirely, because pg_basebackup copies the heap files and row security never '
                . 'applies to a physical read. Connect as a restricted role that is a member of no '
                . 'privileged role — note that granting the table-owning migration role to the runtime role '
                . 'is enough to fail this.',
            );
        }

        $policedTables = self::assertPolicedTablesAreBeyondThisRolesReach($connection);

        self::assertNoTenantPinnedOnTheConnection($connection);

        return $policedTables;
    }

    /**
     * Prove that this role cannot reach *around* the policies rather than through them.
     *
     * The two attributes checked above are not the whole of bypass #0, and this is the hole a round found:
     * a role that is neither a superuser nor `BYPASSRLS` can still see every tenant in two statements if it
     * **owns** a policed table (`SET ROLE owner; ALTER TABLE … DISABLE ROW LEVEL SECURITY`), or erase every
     * tenant's rows in one if it holds **TRUNCATE**, which is never subject to row security at any
     * privilege level. `FORCE ROW LEVEL SECURITY` does not help with either: it stops an owner *skipping*
     * policies, not *removing* them.
     *
     * The table set is derived from the catalogue rather than passed in, deliberately: any table with RLS
     * enabled is by definition tenant-owned, so a table added by a later wave is covered the day it is
     * created and cannot be forgotten from a list. `has_table_privilege` already accounts for privileges
     * held via role membership, and `pg_has_role` on the owner covers reaching ownership by `SET ROLE`
     * from either current_user or session_user, for the same reason as above.
     *
     * @return int the number of policed tables inspected
     *
     * @throws \RuntimeException if any policed table is reachable, or if no table is policed at all
     */
    public static function assertPolicedTablesAreBeyondThisRolesReach(\PDO $connection): int
    {
        $statement = $connection->query(
            'SELECT n.nspname || \'.\' || c.relname AS "table", o.rolname AS owner, '
            . "pg_has_role(session_user, c.relowner, 'MEMBER') "
            . "OR pg_has_role(current_user, c.relowner, 'MEMBER') AS owner_reachable, "
            // TRUNCATE by REACHABILITY, not by inheritance. `has_table_privilege` resolves privileges the
            // way PostgreSQL applies them *now* — inheritably — while `SET ROLE` is authorised by
            // MEMBERSHIP. A grant made `WITH INHERIT FALSE` (the PG16+ way to say "hold this deliberately,
            // not by default") is therefore invisible to has_table_privilege and one statement away from
            // the privilege. Round 5 erased both tenants through exactly that gap, with
            // current_user == session_user throughout, so no DSN trick was even needed. aclexplode is used
            // because it exposes the grantee, which is the thing membership has to be tested against;
            // grantee 0 is PUBLIC.
            . 'EXISTS (SELECT 1 FROM aclexplode(c.relacl) a '
            . "WHERE a.privilege_type = 'TRUNCATE' AND (a.grantee = 0 "
            . "OR pg_has_role(session_user, a.grantee, 'MEMBER') "
            . "OR pg_has_role(current_user, a.grantee, 'MEMBER'))) AS can_truncate, "
            . 'c.relforcerowsecurity AS forced, '
            // THE POLICY EXPRESSION, not merely the two flags. ENABLE + FORCE + `USING (true)` satisfied
            // every earlier check while isolating nothing — a clean verdict on a table readable and
            // writable across tenants. So every policy must reference the tenant GUC; one that does not is
            // either a mistake or a deliberate hole, and both must fail. A table with RLS enabled and no
            // policy at all denies everything, which is fail-closed, so it is counted rather than refused.
            . '(SELECT count(*) FROM pg_policy p WHERE p.polrelid = c.oid) AS policies, '
            . '(SELECT count(*) FROM pg_policy p WHERE p.polrelid = c.oid '
            . 'AND coalesce(pg_get_expr(p.polqual, p.polrelid), \'\') LIKE '
            . '\'%\' || ' . \sprintf("'%s'", self::TENANT_SETTING) . ' || \'%\') AS scoped_policies '
            . 'FROM pg_class c '
            . 'JOIN pg_roles o ON o.oid = c.relowner '
            . 'JOIN pg_namespace n ON n.oid = c.relnamespace '
            // 'p' AS WELL AS 'r'. A PARTITIONED table carries relkind='p' and relrowsecurity=t (its
            // partitions carry f), so `relkind = 'r'` dropped it from the set entirely — ownership,
            // TRUNCATE, FORCE and the non-vacuity count all skipped it. Round 5 read and wrote every
            // tenant's rows through a policed partitioned table that this check reported as clean. Verified
            // that 'p' is the ONLY gap: views and materialised views cannot carry RLS at all
            // (ALTER TABLE ... ENABLE ROW LEVEL SECURITY is rejected on relkind='v').
            . "WHERE c.relrowsecurity AND c.relkind IN ('r', 'p') "
            . "AND n.nspname NOT IN ('pg_catalog', 'information_schema') "
            . 'ORDER BY 1',
        );

        if (false === $statement) {
            throw new \RuntimeException('Could not inspect the policed tables.');
        }

        /** @var list<array{table: string, owner: string, owner_reachable: bool|string, can_truncate: bool|string, forced: bool|string, policies: int|string, scoped_policies: int|string}> $tables */
        $tables = $statement->fetchAll(\PDO::FETCH_ASSOC);

        // A check with no subject matter must not report success — the same vacuity that made a gate print
        // OK after inspecting zero files. If nothing is policed then this connection is subject to no
        // policy at all, which is the state this method exists to rule out, not a clean bill of health.
        if ([] === $tables) {
            throw new \RuntimeException(
                'No table in this database has row-level security enabled, so there is no isolation to be '
                . 'subject to and this check would otherwise pass vacuously. Run the migrations first; if '
                . 'they have run, no tenant-owned table is carrying its policy.',
            );
        }

        $violations = self::policedTableViolations($tables);

        if ([] !== $violations) {
            throw new \RuntimeException(
                'This connection can reach around the row-level security policies rather than through '
                . 'them, so isolation is not in force however correct the policies are: '
                . implode('; ', $violations)
                . '. Migrations must run as a separate owning role, that role must not be granted to the '
                . 'runtime role, and TRUNCATE must be revoked — see infra/README.md.',
            );
        }

        return \count($tables);
    }

    /**
     * Which policed tables this role can reach around, given the catalogue rows.
     *
     * Pure, for the same reason {@see self::roleCanBypassPolicies()} is: the interesting branches need
     * privileges the runtime role must never hold, so they are unit-testable here and separately proven
     * live against the real catalogue by the integration suite.
     *
     * @param list<array{table: string, owner: string, owner_reachable: bool|string, can_truncate: bool|string, forced: bool|string, policies: int|string, scoped_policies: int|string}> $tables
     *
     * @return list<string> one human-readable violation per problem found, empty when the role is safe
     */
    public static function policedTableViolations(array $tables): array
    {
        $violations = [];

        foreach ($tables as $table) {
            if (self::isTrue($table['owner_reachable'])) {
                $violations[] = \sprintf(
                    '%s is owned by %s, which this connection can reach (DISABLE ROW LEVEL SECURITY, or a '
                    . 'USING (true) policy, is then one statement away)',
                    $table['table'],
                    $table['owner'],
                );
            }

            if (self::isTrue($table['can_truncate'])) {
                $violations[] = \sprintf(
                    '%s can be TRUNCATEd by this connection, which removes every tenant\'s rows and is '
                    . 'never subject to row security',
                    $table['table'],
                );
            }

            // Not a bypass this role holds, but a policy that exempts its own owner — worth refusing here
            // because this is the one place that reads relforcerowsecurity, and a migration that ENABLEd
            // without FORCEing has left the owning role exempt on that table.
            if (!self::isTrue($table['forced'])) {
                $violations[] = \sprintf(
                    '%s has row-level security ENABLEd but not FORCEd, so its owner is exempt from its own '
                    . 'policies',
                    $table['table'],
                );
            }

            // THE POLICY EXPRESSION. Both flags can be set and the policy can still isolate nothing:
            // `USING (true)` passed every earlier version of this check while allowing cross-tenant reads
            // AND writes. Any policy whose qualifier does not mention the tenant setting is a hole,
            // whether it was added by mistake or on purpose, so the comparison is "every policy is
            // scoped", not "at least one is".
            $policies = (int) $table['policies'];
            $scoped = (int) $table['scoped_policies'];

            if ($policies > $scoped) {
                $violations[] = \sprintf(
                    '%s carries %d polic%s that never reference %s, so row-level security is enabled and '
                    . 'forced while isolating nothing (a USING (true) policy looks identical to a correct '
                    . 'one in pg_class)',
                    $table['table'],
                    $policies - $scoped,
                    1 === $policies - $scoped ? 'y' : 'ies',
                    self::TENANT_SETTING,
                );
            }
        }

        return $violations;
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
     * checked here is narrower, and an earlier version of this docblock called it "sufficient", which was
     * false in two ways a round proved:
     *
     *  - **It cannot tell where the value came from.** From inside a transaction, a transaction-local
     *    write and a session-scope one read identically, so a *second* `bind()` in the same transaction
     *    trips this check as well. That is refused deliberately rather than tolerated — switching tenant
     *    inside one transaction would leave statements already executed under the first tenant in the same
     *    atomic unit as statements under the second — but the message must not blame a DSN option for it.
     *  - **It can be masked.** Anything able to run `set_config(…, '', true)` before `bind()` hides a live
     *    session pin behind a transaction-local empty string, and the pin returns on COMMIT. That actor
     *    could equally bind itself to any tenant directly, so it buys them nothing; the honest statement
     *    is that this guard raises the cost of the fourth bypass rather than closing it. Closing it needs
     *    a re-check when the connection is *released*, which needs a connection lifecycle this wave has
     *    no ORM to hook — recorded as owed in docs/plans/build-waves.plan.md (R4-3).
     *
     * `''` and NULL are both "no tenant", exactly as above.
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
            'Refusing to bind: %s already reads "%s". Either this transaction has already bound a tenant — '
            . 'rebinding inside one transaction is refused, because statements already executed under the '
            . 'first tenant would share an atomic unit with statements under the second; commit and open a '
            . 'new transaction per tenant — or something else on this connection set it (a DSN option, '
            . 'PGOPTIONS, a session-scope write), in which case that value returns on COMMIT and unbound '
            . 'statements would silently read that tenant.',
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

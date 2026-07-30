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

        $mismatch = self::describeBindingMismatch($expected, $actual);

        if (null !== $mismatch) {
            throw new \RuntimeException($mismatch);
        }
    }

    /**
     * Re-verify that the connection is STILL scoped to the tenant the application believes it is.
     *
     * WHY THIS EXISTS, and why it is not paranoia. `bind()` writes the tenant transaction-locally, which is
     * what stops a binding leaking to whoever gets the connection next. The consequence nobody had tested:
     * **a savepoint rollback reverts it.** `ROLLBACK TO SAVEPOINT` restores every transaction-local setting to
     * its value at the savepoint, so a bind that happened *inside* the savepoint is undone while the PHP-side
     * `TenantContext` still believes the new tenant. Every subsequent query is then scoped to the OLD tenant
     * and labelled by the application as the NEW one — a silent cross-tenant read.
     *
     * The residue that recorded this said it was "not reachable today (PDO forbids nested transactions)".
     * That is FALSE, and reproducing it took nine lines: PDO forbids a nested `beginTransaction()`, not a
     * `SAVEPOINT` issued as ordinary SQL — which is precisely what Doctrine emits for a nested transaction.
     * [Verified 2026-07-30 on a real connection, with no Doctrine and no ORM: bind A, `SAVEPOINT sp1`, bind B,
     * `ROLLBACK TO SAVEPOINT sp1`, and `current_setting` reads `tenant-A` while the context holds `tenant-B`.]
     * Fourth documented impossibility refuted this session; the lesson is in CLAUDE.md § Gotchas.
     *
     * The database cannot catch this on its own — it scopes correctly to what the GUC says, and it has no way
     * to know what the application believes. So the check has to be app-side, and it has to be a re-read
     * rather than a cached flag, because the whole failure is that the cached belief is the stale thing.
     *
     * **Obligation, recorded so it cannot be forgotten:** every tenant-scoped repository must call this after
     * any savepoint release or rollback, and Wave 1 owes the wiring. Nothing calls it today because no
     * repository exists yet — which is why this docblock states the obligation instead of the method quietly
     * existing. A capability with a test and a recorded obligation is not the same thing as a rule nothing
     * consults; if Wave 1 lands repositories without these calls, that is a completeness-reviewer P0.
     */
    public function assertStillBoundTo(\PDO $connection, TenantContext $context): void
    {
        // A TENANT-LESS CONTEXT IS THE OTHER HALF OF THE SAME DIVERGENCE, not an error. Round 9 (P1): this
        // threw NoCurrentTenant when the context held no tenant — but a savepoint rollback, or a `clear()` for
        // the genuinely cross-tenant work TenantContext's own docblock names (installation, a global health
        // check, a cross-tenant migration), can leave the GUC bound to tenant A while the context holds
        // nothing. Every statement is then still scoped to A while the application believes it is reading
        // everything, so a cross-tenant report silently returns one tenant's rows as the whole set.
        //
        // Throwing made it undetectable in practice: NoCurrentTenant's own message says "ask
        // TenantContext::hasTenant() first", so a correctly-written caller guards this call with
        // `if ($context->hasTenant())` and runs NO check in exactly the state that needed one.
        // So: expect the GUC to be EMPTY, and say so when it is not.
        if (!$context->hasTenant()) {
            $unbound = self::readTenantSetting($connection);

            if ('' === $unbound) {
                return;
            }

            throw new \RuntimeException(\sprintf(
                'Tenant binding DIVERGED: the application believes it holds NO tenant — so it expects to see '
                . 'every tenant\'s rows — but the connection is still scoped to "%s". A cross-tenant read '
                . 'would silently return one tenant\'s rows as the whole set. Clear the binding, or bind '
                . 'deliberately.',
                $unbound,
            ));
        }

        $expected = $context->tenantId()->toString();

        $actual = self::readTenantSetting($connection);

        if ($actual === $expected) {
            return;
        }

        throw new \RuntimeException(\sprintf(
            'Tenant binding DIVERGED: the application believes tenant "%s" but the connection is scoped to '
            . '"%s". A savepoint rollback reverts a transaction-local binding while leaving the PHP-side '
            . 'context untouched, so continuing would read one tenant\'s rows under another tenant\'s name. '
            . 'ABORT this unit of work: do NOT try to re-bind, because bind() refuses while the reverted '
            . 'value is present, and the only way to force it past that refusal is the empty-string masking '
            . 'this class documents as a bypass. Roll back and open a new transaction.',
            $expected,
            '' !== $actual ? $actual : '<unset>',
        ));
    }

    /**
     * The tenant setting as the connection currently sees it, `''` when unset.
     *
     * Shared by both divergence branches so they cannot drift apart — and `coalesce` to `''` rather than null
     * because "never set" and "explicitly emptied" are the same thing to a caller asking "am I still bound?".
     * `assertSessionTenantIsUnset()` keeps its own read for two narrower reasons — parameter binding and its own
     * message — NOT because it distinguishes those states: it treats null, false and '' identically, exactly as
     * this does. Round 10 filed the earlier version of this sentence, which claimed the opposite and pointed at
     * a comment that contradicted it.
     */
    private static function readTenantSetting(\PDO $connection): string
    {
        $statement = $connection->prepare(
            \sprintf("SELECT coalesce(current_setting(%s, true), '')", \sprintf("'%s'", self::TENANT_SETTING)),
        );
        $statement->execute();

        $value = $statement->fetchColumn();

        // THROW on a non-string; do NOT map it to ''. ROUND 10 (P2, fail-open): returning '' turned "the read
        // failed" into "not bound", so the tenant-less branch certified a connection genuinely scoped to
        // tenant A as UNBOUND — the exact cross-tenant state that branch exists to detect. This class's own
        // describeBindingMismatch() docblock already names the value: "`false` when the fetch failed, and
        // anything else means the driver surprised us". A failure signal is not a value.
        if (!\is_string($value)) {
            throw new \RuntimeException(\sprintf(
                'Could not read the %s setting to verify the tenant binding: the driver returned %s. Refusing '
                . 'to continue, because an unverifiable binding is indistinguishable from a wrong one.',
                self::TENANT_SETTING,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * Whether the value read back after a binding is the value that was written.
     *
     * Pure, and extracted for the same reason {@see self::roleCanBypassPolicies()} and
     * {@see self::policedTableViolations()} are: it makes the classification directly assertable without
     * arranging a database that misbehaves.
     *
     * **Not because the branch is unreachable — it is reachable, and a test drives it.** An earlier version of
     * this sentence said "otherwise unreachable from a test", which was refuted by code committed in the same
     * change: `LyingStatement` in TenantIsolationTest substitutes a `PDOStatement` subclass through PDO's
     * `ATTR_STATEMENT_CLASS` and drives `bind()` into the mismatch on a real connection. Extraction is still
     * worth having; the claim of impossibility was not, and is exactly what CLAUDE.md § Gotchas now forbids. Round 5
     * deleted this comparison outright and the whole suite stayed green — the test named for it re-queried
     * the GUC itself rather than driving `bind()` into the mismatch, so `bind()` would have silently
     * succeeded on a binding that never took, which is the exact failure the read-back exists to prevent.
     *
     * @param string $expected the tenant id that was written
     * @param mixed $actual whatever `set_config` returned — a string when it worked, `false` when the
     *                      fetch failed, and anything else means the driver surprised us
     *
     * @return string|null the message to raise, or null when the binding took
     */
    public static function describeBindingMismatch(string $expected, mixed $actual): ?string
    {
        if ($actual === $expected) {
            return null;
        }

        return \sprintf(
            'Tenant isolation did not take effect: expected the %s session setting to be "%s" but it reads '
            . '"%s". Refusing to continue on an unscoped connection.',
            self::TENANT_SETTING,
            $expected,
            \is_string($actual) ? $actual : get_debug_type($actual),
        );
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
            . 'bool_or(rolreplication) AS rolreplication, '
            // PREDEFINED ROLES, and this is the REPLICATION finding's twin rather than a lesser cousin.
            // PostgreSQL's `pg_*` roles are ordinary pg_roles rows with all three attributes FALSE, so
            // membership in one is invisible to an attribute check. Round 6 proved two of them reach superuser:
            // `pg_execute_server_program` runs `COPY (…) TO PROGRAM`, which executes as the postgres OS user
            // and hands back a superuser connection over the local socket; `pg_write_server_files` writes
            // arbitrary files as that same user. Both were certified CLEAN, with correctly-policed SQL
            // throughout — the same shape that made the REPLICATION verdict convincing.
            //
            // ANY `pg_*` membership is refused rather than an enumerated two, deliberately: a future
            // PostgreSQL adding a predefined role is then covered on the day it exists, and a runtime role
            // has no business holding any of them. Monitoring needs belong on a separate role. Note this
            // also catches `pg_database_owner`, which is implicitly held by a database's owner — a connection
            // that owns the database is already refused for owning its tables, so that is not a new failure.
            . "(SELECT string_agg(pr.rolname, ', ' ORDER BY pr.rolname) FROM pg_roles pr "
            // `pg_database_owner` is excluded, and precisely: membership in it is granted IMPLICITLY to
            // whoever owns the current database, and it confers no capability that reaches around row
            // security — no file access, no program execution, no attribute. Refusing it would report every
            // owner connection under the wrong heading and bury the real finding, which is that the
            // connection owns TABLES. Every other pg_* role stays refused.
            . "WHERE pr.rolname LIKE 'pg\\_%' AND pr.rolname <> 'pg_database_owner' "
            . "AND (pg_has_role(session_user, pr.oid, 'MEMBER') "
            . "OR pg_has_role(current_user, pr.oid, 'MEMBER'))) AS predefined_roles "
            . 'FROM pg_roles WHERE pg_has_role(session_user, oid, \'MEMBER\') '
            . "OR pg_has_role(current_user, oid, 'MEMBER')",
        );

        if (false === $statement) {
            throw new \RuntimeException('Could not inspect the current database role.');
        }

        /** @var array{rolsuper: bool|string, rolbypassrls: bool|string, rolreplication: bool|string, predefined_roles: string|null}|false $role */
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

        if (\is_string($role['predefined_roles'] ?? null) && '' !== $role['predefined_roles']) {
            throw new \RuntimeException(\sprintf(
                'This connection can reach the predefined role(s) %s. Those carry none of the three role '
                . 'attributes checked above, so they look harmless — but pg_execute_server_program runs '
                . 'programs as the postgres OS user (and thence a superuser connection over the local '
                . 'socket), and pg_write_server_files writes arbitrary files as that user. Row-level '
                . 'security is not involved in either. A runtime role must be a member of no pg_* role; '
                . 'monitoring and maintenance needs belong on a separate role.',
                $role['predefined_roles'],
            ));
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

        self::assertNoRlsExemptObjectIsReadable($connection);

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
     * enabled is by definition tenant-owned, so a table added by a later wave is covered the day it is created
     * and cannot be omitted from a list somebody has to maintain.
     *
     * **What that does NOT cover, stated because an earlier version of this sentence implied the opposite.** The
     * inverse is the dangerous direction and it is the likely Wave 1 mistake: a tenant-owned table whose
     * migration **forgot** `ENABLE ROW LEVEL SECURITY` is invisible here *by construction*, because it never
     * enters the derived set — **unless it happens to sit in a policed inheritance hierarchy**, in which case
     * the descendant and ancestor arms below do reach it. A standalone forgotten table is still nobody's
     * business but the **schema gate's** — recorded as owed, and a P0 at the first Wave 1 migration — and
     * reading this docblock as though it covered that case is how that gate would come to seem redundant.
     *
     * TRUNCATE and ownership are both tested by **REACHABILITY**, never by `has_table_privilege`. That
     * function resolves privileges the way PostgreSQL applies them right now — *inheritably* — while `SET
     * ROLE` is authorised by MEMBERSHIP, so a grant made `WITH INHERIT FALSE` is invisible to it and one
     * statement away from the privilege. An earlier version of this paragraph asserted the opposite
     * ("`has_table_privilege` already accounts for privileges held via role membership") while the code
     * beneath it had already moved to `aclexplode` plus `pg_has_role`; the sentence was a stale description of
     * a rejected approach, and leaving it there is how the rejected approach gets reintroduced.
     *
     * **Scope, stated because it is a real boundary and not an oversight.** Every catalogue this reads is
     * per-database, so the verdict covers `current_database()` and nothing else. PostgreSQL grants `CONNECT` to
     * PUBLIC on every new database, so a runtime role can generally reach `postgres`, `template1` and any
     * sibling database on the cluster, where this check has said nothing at all. That matters the day twes-in
     * runs more than one tenant database on one cluster.
     *
     * It is deliberately NOT asserted here. An assertion would fail on essentially every development cluster
     * on earth, because those PUBLIC grants are the shipped default — and a check that always fails is a check
     * somebody disables, which is strictly worse than a documented scope. The requirement belongs to the
     * cluster: `REVOKE CONNECT ON DATABASE … FROM PUBLIC` for every database, which
     * scripts/dev/provision-test-database.sh already does for the one it creates. Owed to `infra/` in Wave 12
     * for the rest, and recorded in docs/plans/build-waves.plan.md so it is not rediscovered as a finding.
     *
     * @return int the number of policed tables inspected
     *
     * @throws \RuntimeException if any policed table is reachable, or if no table is policed at all
     */
    public static function assertPolicedTablesAreBeyondThisRolesReach(\PDO $connection): int
    {
        $statement = $connection->query(
            // The SUBJECT set is "policed tables, plus every partition of a policed partitioned parent".
            //
            // That second arm is not a refinement, it closes a permanent blind spot: a PARTITION of a policed
            // parent carries `relrowsecurity = f` of its own, and RLS on a parent does NOT police direct
            // access to a partition — `SELECT * FROM invoices_2026` bypasses the parent's policy entirely.
            // Round 5 added `relkind = 'p'` so the parent was inspected; round 6 proved that no relkind list
            // can ever reach the partitions, because they are excluded by the RLS flag rather than by kind.
            // Tenant A could read, overwrite and delete tenant B's rows through one while this check reported
            // clean. `pg_partition_tree` returns no rows for a plain table, hence the UNION rather than a
            // single expression.
            'WITH RECURSIVE policed AS ('
            . '  SELECT c.oid FROM pg_class c '
            // relpersistence = 'p', and this arm is FAIL-OPEN without it. Every session's TEMPORARY relations
            // are visible in pg_class to every other session, so round 12 demonstrated both directions on one
            // database: a sibling connection switching RLS on for a scratch temp table made this method refuse
            // every OTHER connection, naming a relation the refused connection cannot read, locate or drop —
            // and, worse, a concurrent session holding a correctly-policed temp table satisfies the
            // `[] === $tables` vacuity guard, so the check reports "1 policed table inspected, clean" on a
            // database where NO PERMANENT TABLE IS POLICED AT ALL. A tenant-owned table is never temporary.
            //
            // The sibling method added in the very same diff filters with pg_my_temp_schema() and documents
            // exactly this hazard; the insight was applied in one place only, which is the defect shape this
            // file's history records more than any other.
            . "  WHERE c.relrowsecurity AND c.relpersistence = 'p' AND c.relkind IN ('r', 'p')"
            . '), descendant AS ('
            . '  SELECT oid FROM policed'
            . '  UNION'
            // pg_inherits, RECURSIVELY — not pg_partition_tree. Round 6 used the latter and round 7 showed it
            // knows only DECLARATIVE partitioning: a child created with the older `INHERITS (parent)` syntax
            // has `relispartition = f`, appears in no partition tree, carries `relrowsecurity = f` of its own,
            // and was therefore never inspected — full cross-tenant read, update, delete AND insert while the
            // verdict was clean. `pg_inherits` is the catalogue behind BOTH mechanisms, so recursing it covers
            // declarative partitions (including multi-level and cross-schema, both re-verified) and legacy
            // inheritance children in one expression, and covers whatever PostgreSQL adds next that reuses it.
            . '  SELECT i.inhrelid FROM pg_inherits i JOIN descendant d ON d.oid = i.inhparent'
            . '), ancestor AS ('
            // AND THE SAME CATALOGUE WALKED **UPWARD**, which is the sixth bypass class and the one every arm
            // above is structurally blind to: all of them read `d.oid = i.inhparent` and emit `i.inhrelid`, so
            // they can only ever reach descendants. PostgreSQL's inheritance semantics make the inverse the
            // more dangerous direction — a child's policies are NOT applied when the child is read through its
            // parent, so an UNPOLICED PARENT of policed children returns every descendant's rows to every
            // tenant and accepts writes into any of them, while the children are correctly policed and the
            // verdict reads CLEAN. Round 11 demonstrated read, update and delete through one.
            //
            // It is the natural Wave 1 supertype (`documents` with `invoices` and `credit_notes` under it),
            // where somebody polices the leaves because those are the tables holding the data.
            . '  SELECT i.inhparent AS oid FROM pg_inherits i JOIN policed p ON p.oid = i.inhrelid'
            . '  UNION'
            . '  SELECT i.inhparent FROM pg_inherits i JOIN ancestor a ON a.oid = i.inhrelid'
            . '), subject AS ('
            . '  SELECT oid, false AS via_ancestry FROM descendant'
            . '  UNION ALL'
            // Ancestors that are ALREADY in the descendant set are excluded rather than duplicated: a policed
            // ancestor is in `policed` and needs no second row, and an unpoliced table that is both an ancestor
            // of one policed table and a descendant of another is a violation either way. The flag exists only
            // so the message names the relationship that makes the table dangerous — telling a reader to police
            // "a child" when the children are already policed sends them to the wrong table.
            . '  SELECT oid, true FROM ancestor'
            . '  WHERE oid NOT IN (SELECT oid FROM descendant)'
            . ') '
            . 'SELECT n.nspname || \'.\' || c.relname AS "table", o.rolname AS owner, '
            . 's.via_ancestry, '
            . "pg_has_role(session_user, c.relowner, 'MEMBER') "
            . "OR pg_has_role(current_user, c.relowner, 'MEMBER') AS owner_reachable, "
            // TRUNCATE by REACHABILITY, not by inheritance. `has_table_privilege` resolves privileges the
            // way PostgreSQL applies them *now* — inheritably — while `SET ROLE` is authorised by
            // MEMBERSHIP. A grant made `WITH INHERIT FALSE` (the PG16+ way to say "hold this deliberately,
            // not by default") is therefore invisible to has_table_privilege and one statement away from
            // the privilege. Round 5 erased both tenants through exactly that gap. aclexplode is used
            // because it exposes the grantee, which is the thing membership has to be tested against;
            // grantee 0 is PUBLIC.
            . 'EXISTS (SELECT 1 FROM aclexplode(c.relacl) a '
            . "WHERE a.privilege_type = 'TRUNCATE' AND (a.grantee = 0 "
            . "OR pg_has_role(session_user, a.grantee, 'MEMBER') "
            . "OR pg_has_role(current_user, a.grantee, 'MEMBER'))) AS can_truncate, "
            . 'c.relforcerowsecurity AS forced, '
            . 'c.relrowsecurity AS rls_enabled, '
            . "c.relispartition AS is_partition, "
            // EVERY POLICY, both halves of it, as text — rather than a count computed in SQL.
            //
            // Two defects round 6 proved, both in the version that counted in SQL. It read `polqual` (the
            // USING clause) and never `polwithcheck`, so a policy with a scoped USING and `WITH CHECK (true)`
            // was certified clean and permitted a cross-tenant INSERT — PostgreSQL only reuses USING as a
            // write check for UPDATE and INSERT ... RETURNING, so a plain INSERT is guarded by WITH CHECK
            // alone. And it matched with `LIKE '%twes.tenant_id%'`, which proves a policy MENTIONS the
            // setting rather than isolates by it: `USING (scoped OR current_setting('twes.support_mode') =
            // 'on')` passed, and setting a custom GUC needs no privilege at all, so the unprivileged runtime
            // role flipped it and read every tenant. The same match also REFUSED correct policies.
            //
            // So the expressions come back as text and are compared in PHP against the exact rendering
            // policySqlFor() produces. See policyExpressionIsCanonical().
            . 'coalesce(('
            . '  SELECT json_agg(json_build_object('
            . "    'qual', pg_get_expr(p2.polqual, p2.polrelid),"
            . "    'check', pg_get_expr(p2.polwithcheck, p2.polrelid),"
            . "    'permissive', p2.polpermissive"
            . '  )) FROM pg_policy p2 WHERE p2.polrelid = c.oid'
            . "), '[]') AS policies "
            . 'FROM subject s '
            . 'JOIN pg_class c ON c.oid = s.oid '
            . 'JOIN pg_roles o ON o.oid = c.relowner '
            . 'JOIN pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE n.nspname NOT IN ('pg_catalog', 'information_schema') "
            . 'ORDER BY 1',
        );

        if (false === $statement) {
            throw new \RuntimeException('Could not inspect the policed tables.');
        }

        /** @var list<array{table: string, owner: string, via_ancestry: bool|string, owner_reachable: bool|string, can_truncate: bool|string, forced: bool|string, rls_enabled: bool|string, is_partition: bool|string, policies: string}> $tables */
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
     * SQL for "this connection can become the role in `$roleOid`", as a boolean expression.
     *
     * ONE definition, referenced everywhere, because the wrong definition is the recurring defect in this
     * class. `has_table_privilege`/`has_function_privilege` resolve privileges the way PostgreSQL applies
     * them at this instant — **inheritably** — while `SET ROLE` is authorised by **MEMBERSHIP**. A grant made
     * `WITH INHERIT FALSE` (the PG16+ way to say "hold this deliberately, not by default") is therefore
     * invisible to the `has_*_privilege` family and exactly one statement away from the privilege. This
     * project's own fixture provisions that shape on purpose (`twes_truncator`, granted
     * `WITH INHERIT FALSE`), and rounds 5 and 11 each found a check that used the blind function.
     *
     * BOTH `session_user` and `current_user`, and the pair is not redundant: a connection sitting inside
     * `SET ROLE` has an innocent-looking `current_user` while `session_user` still names the role whose
     * memberships decide what it can become next, and `RESET ROLE` needs no privilege at all.
     */
    private static function roleIsReachableSql(string $roleOid): string
    {
        return \sprintf(
            "(pg_has_role(session_user, %s, 'MEMBER') OR pg_has_role(current_user, %s, 'MEMBER'))",
            $roleOid,
            $roleOid,
        );
    }

    /**
     * SQL for "this connection holds `$privilege` on the object whose ACL is `$acl`", by REACHABILITY.
     *
     * `aclexplode` is used rather than `has_*_privilege` for the reason {@see self::roleIsReachableSql()}
     * gives: it exposes the **grantee**, which is the thing membership has to be tested against. Grantee 0 is
     * PUBLIC.
     *
     * `$aclDefaultGrantsIt` is the whole subtlety of the function case. A NULL ACL means "PostgreSQL's default
     * privileges apply", and that default is **not the same for every object kind**: a table's default grants
     * nothing to anybody but its owner, while a **function's default grants EXECUTE to PUBLIC**. So a
     * `SECURITY DEFINER` function with an untouched ACL is callable by every role on the server, and reading a
     * NULL `proacl` as "no grants" makes the check certify precisely the shape it exists to refuse.
     */
    private static function privilegeIsReachableSql(
        string $acl,
        string $privilege,
        bool $aclDefaultGrantsIt,
    ): string {
        return \sprintf(
            '(%s%s IS NOT NULL AND EXISTS (SELECT 1 FROM aclexplode(%s) a '
            . "WHERE a.privilege_type = '%s' AND (a.grantee = 0 "
            . "OR pg_has_role(session_user, a.grantee, 'MEMBER') "
            . "OR pg_has_role(current_user, a.grantee, 'MEMBER'))))",
            $aclDefaultGrantsIt ? $acl . ' IS NULL OR ' : '',
            $acl,
            $acl,
            $privilege,
        );
    }

    /**
     * The four privileges on the relation's OWN acl, plus every COLUMN acl on it.
     *
     * Two P0s from round 12, one filter, and both were narrowings nobody had questioned:
     *
     * **Column privileges are not in `relacl`.** They live in `pg_attribute.attacl`, and a column grant
     * records nothing in the relation's own ACL. So a non-`security_invoker` view reachable only by
     * `GRANT SELECT (label)` was excluded from the result set and the verdict read CLEAN while every tenant's
     * rows were readable through it. [Verified on this server: after `GRANT SELECT (label)`,
     * `has_table_privilege` is false, `has_column_privilege` is true, and `attacl` reads
     * `label={twes=r/postgres}`.] The pre-round-11 `has_table_privilege` had the identical hole, so this was
     * long-standing rather than a regression — which is exactly why rewriting that line without widening it
     * was worth catching.
     *
     * **A cross-tenant WRITE needs no SELECT.** Writes through a view without `security_invoker` execute with
     * the view OWNER's privileges and the base table's policies are evaluated as that owner, so a plain
     * `INSERT ... VALUES` — requiring no read privilege at all — plants a row in whatever tenant the caller
     * names. `UPDATE`/`DELETE` give overwrite and erase, and `UPDATE ... RETURNING` gives the read back too, so
     * "it is only a write" is not a mitigation. An insert-only journal or audit view is an ordinary shape.
     *
     * `TRUNCATE` and `REFERENCES` are deliberately absent: neither reaches through a view, and the policed
     * tables' own TRUNCATE reachability is asserted separately by
     * {@see self::assertPolicedTablesAreBeyondThisRolesReach()}.
     */
    private static function anyAccessIsReachableSql(): string
    {
        $arms = [];

        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            $arms[] = self::privilegeIsReachableSql('c.relacl', $privilege, false);
            // The column arm, correlated on this relation. `aclexplode` over each column's own ACL, with the
            // same grantee-membership test as everywhere else in this class — grantee 0 is PUBLIC.
            $arms[] = \sprintf(
                'EXISTS (SELECT 1 FROM pg_attribute att, aclexplode(att.attacl) ca '
                . 'WHERE att.attrelid = c.oid AND att.attacl IS NOT NULL '
                . "AND ca.privilege_type = '%s' AND (ca.grantee = 0 "
                . "OR pg_has_role(session_user, ca.grantee, 'MEMBER') "
                . "OR pg_has_role(current_user, ca.grantee, 'MEMBER')))",
                $privilege,
            );
        }

        return '(' . implode(' OR ', $arms) . ')';
    }

    /**
     * The exact `USING`/`WITH CHECK` expression a correct policy renders to, for a given tenant column.
     *
     * PostgreSQL normalises a policy expression when it stores it, and `pg_get_expr()` renders that
     * normalised form **deterministically** — the canonical policy comes back byte-identical on every run.
     * That is what makes an exact comparison possible, and an exact comparison is the only thing that works
     * here: round 6 defeated a substring test (`LIKE '%twes.tenant_id%'`) in both directions at once, with
     * `USING (scoped OR current_setting('twes.support_mode') = 'on')` passing while an unprivileged role
     * flipped that second GUC and read every tenant, and with correct policies being refused for spelling the
     * setting name differently.
     *
     * Comparing to this makes {@see self::policySqlFor()} the single source of truth it already claims to be
     * rather than a suggestion. The trade is stated plainly: if a future PostgreSQL changes how it renders
     * this expression, every policed table is reported as unscoped. That fails CLOSED and the integration
     * suite asserts the canonical policy passes, so such a change breaks the build loudly on the first run —
     * which is the correct direction for a control whose failure mode is a silent cross-tenant read.
     */
    public static function canonicalPolicyExpression(string $tenantColumn = 'company_id'): string
    {
        return \sprintf(
            "(%s = (NULLIF(current_setting('%s'::text, true), ''::text))::uuid)",
            $tenantColumn,
            self::TENANT_SETTING,
        );
    }

    /**
     * The column a canonical policy expression scopes, or null when the expression is not canonical.
     *
     * Exists so the caller can require ONE column per table rather than one per clause — see
     * {@see self::policedTableViolations()} for the cross-tenant INSERT that per-clause checking allowed.
     */
    public static function policyExpressionColumn(?string $expression): ?string
    {
        if (null === $expression) {
            return null;
        }

        if (1 !== preg_match('/^\\(([a-z_][a-z0-9_]*) = /', $expression, $matches)) {
            return null;
        }

        return $expression === self::canonicalPolicyExpression($matches[1]) ? $matches[1] : null;
    }

    /**
     * Whether one rendered expression is the canonical tenant predicate.
     *
     * NULL is accepted, and that is not laxity: a per-command policy legitimately has one half unset —
     * `FOR INSERT` carries only `WITH CHECK`, so its `polqual` is NULL, and `FOR ALL` may omit `WITH CHECK`,
     * in which case PostgreSQL reuses `USING` as the write check. The caller rejects the case where BOTH
     * halves are NULL, which is the only combination that means "this policy constrains nothing".
     *
     * The column name is the sole degree of freedom, extracted from the expression itself and required to be
     * a plain identifier — so `USING (true OR company_id = …)` cannot pass by containing the canonical form
     * as a substring, because the whole expression must equal it.
     */
    public static function policyExpressionIsCanonical(?string $expression): bool
    {
        if (null === $expression) {
            return true;
        }

        if (1 !== preg_match('/^\(([a-z_][a-z0-9_]*) = /', $expression, $matches)) {
            return false;
        }

        return $expression === self::canonicalPolicyExpression($matches[1]);
    }

    /**
     * Which policed tables this role can reach around, given the catalogue rows.
     *
     * Pure, for the same reason {@see self::roleCanBypassPolicies()} is: the interesting branches need
     * privileges the runtime role must never hold, so they are unit-testable here and separately proven
     * live against the real catalogue by the integration suite.
     *
     * @param list<array{table: string, owner: string, via_ancestry?: bool|string, owner_reachable: bool|string, can_truncate: bool|string, forced: bool|string, rls_enabled: bool|string, is_partition: bool|string, policies: string}> $tables
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

            // A relation in a policed hierarchy with row-level security switched off on ITSELF. Both
            // directions leak, for opposite reasons, and the message has to say which — a reader told to
            // police the wrong end of the hierarchy fixes nothing.
            if (!self::isTrue($table['rls_enabled'])) {
                // The relationship is named from a column that was fetched, rather than asserted.
                // `is_partition` was selected and never read, so this message called every unpoliced child a
                // "partition" — which became a false statement the moment the subject set grew to cover legacy
                // `INHERITS` children, and they are exactly the case round 7 found. `via_ancestry` is the same
                // discipline applied to round 11's ancestor arm; it is optional in the signature so that the
                // pure unit tests written before that arm existed still describe a valid catalogue row.
                $violations[] = self::isTrue($table['via_ancestry'] ?? false)
                    ? \sprintf(
                        '%s is an unpoliced ANCESTOR of a policed table, and a child\'s policy is NOT applied '
                        . 'when the child is read through its parent — so every descendant\'s rows are '
                        . 'readable and writable through it by every tenant, however correct the '
                        . "descendants' own policies are. Police the parent too",
                        $table['table'],
                    )
                    : \sprintf(
                        '%s is %s of a policed table but has no row-level security of its own, and a parent\'s '
                        . "policy does NOT cover direct access to a child — every tenant's rows are readable "
                        . 'and writable through it',
                        $table['table'],
                        self::isTrue($table['is_partition'])
                            ? 'a partition'
                            : 'an INHERITS child (legacy table inheritance, not declarative partitioning)',
                    );

                // Nothing below can be judged: an unpoliced relation has no policies to inspect.
                continue;
            }

            if (!self::isTrue($table['forced'])) {
                $violations[] = \sprintf(
                    '%s has row-level security ENABLEd but not FORCEd, so its owner is exempt from its own '
                    . 'policies',
                    $table['table'],
                );
            }

            /** @var list<array{qual: string|null, check: string|null, permissive: bool}> $policies */
            $policies = json_decode($table['policies'], true, 512, \JSON_THROW_ON_ERROR);

            // Every column any permissive policy on this table scopes. One table has one tenant column; more
            // than one means at least one policy is guarding the wrong thing, and the class cannot tell which
            // — so it reports the disagreement rather than guessing.
            $tableColumns = [];

            foreach ($policies as $policy) {
                // RESTRICTIVE policies are ANDed, so an unscoped one only ever narrows access and cannot be
                // a bypass. PERMISSIVE policies are ORed, which is what makes a single unscoped one fatal.
                if (!self::isTrue($policy['permissive'])) {
                    continue;
                }

                if (null === $policy['qual'] && null === $policy['check']) {
                    $violations[] = \sprintf(
                        '%s carries a policy that constrains neither reads nor writes',
                        $table['table'],
                    );

                    continue;
                }

                // BOTH halves. Reading only `USING` certified a policy with `WITH CHECK (true)` as clean and
                // permitted a cross-tenant INSERT: PostgreSQL reuses USING as a write check for UPDATE and
                // INSERT ... RETURNING, but a plain INSERT is guarded by WITH CHECK alone.
                foreach (['qual' => 'USING', 'check' => 'WITH CHECK'] as $half => $clause) {
                    if (self::policyExpressionIsCanonical($policy[$half])) {
                        continue;
                    }

                    $violations[] = \sprintf(
                        '%s has a policy whose %s clause is not the canonical tenant predicate: %s. Emit '
                        . 'policies with policySqlFor() — mentioning %s is not the same as isolating by it, '
                        . 'and an OR branch beside it reopens the whole table',
                        $table['table'],
                        $clause,
                        (string) $policy[$half],
                        self::TENANT_SETTING,
                    );
                }

                // AND BOTH HALVES MUST NAME THE SAME COLUMN. Checking each half in isolation asked only "is
                // this canonical for SOME column" — so `USING (company_id = …)` beside
                // `WITH CHECK (audit_tenant = …)` was two individually-canonical halves, no violation, and a
                // plain INSERT (guarded by WITH CHECK alone) planted a row in another tenant. Round 7 proved
                // it. Any denormalised tenant-ish column the inserting session controls will do, which is why
                // "the column name is the only degree of freedom" has to mean ONE degree per table rather than
                // one per clause.
                $columns = array_unique(array_filter([
                    self::policyExpressionColumn($policy['qual']),
                    self::policyExpressionColumn($policy['check']),
                ], static fn(?string $column): bool => null !== $column));

                if (\count($columns) > 1) {
                    $violations[] = \sprintf(
                        '%s has a policy whose USING and WITH CHECK clauses scope DIFFERENT columns (%s). '
                        . 'Each half is individually well-formed, which is what makes this dangerous: the '
                        . 'write check then guards a column the caller may control, so a row can be planted '
                        . 'in another tenant',
                        $table['table'],
                        implode(' vs ', $columns),
                    );
                }

                $tableColumns = [...$tableColumns, ...$columns];
            }

            // ACROSS policies too, not only within one. Two permissive policies scoping different columns OR
            // together exactly as a single mismatched policy does.
            $distinct = array_values(array_unique($tableColumns));

            if (\count($distinct) > 1) {
                $violations[] = \sprintf(
                    '%s has permissive policies scoping DIFFERENT columns (%s). Permissive policies are ORed, '
                    . 'so the loosest one decides — a table has one tenant column',
                    $table['table'],
                    implode(' vs ', $distinct),
                );
            }
        }

        return $violations;
    }

    /**
     * Refuse a connection that can read an object which BORROWS a privileged role's RLS exemption.
     *
     * **The fifth path, and the one every other check in this class is structurally blind to.** Everything
     * above asks about roles *reachable* from the connection. But PostgreSQL will happily execute part of a
     * query as a role you cannot reach:
     *
     *  - **A view** evaluates row-level security as its OWNER unless `security_invoker = true` — and
     *    `security_invoker` defaults to **false**. A view owned by a `BYPASSRLS` role therefore returns every
     *    tenant's rows, and accepts writes into any tenant, to a caller holding nothing but an ordinary
     *    `SELECT`/`UPDATE` grant.
     *  - **A materialised view** cannot carry RLS at all. It is a plaintext snapshot of whatever the refreshing
     *    role could see, so one over tenant-owned data is a cross-tenant read by construction.
     *  - **A `SECURITY DEFINER` function** runs as its owner, with the same consequence — and **not only when
     *    that owner is privileged**. Round 11 found this arm filtered to `rolsuper OR rolbypassrls`, which
     *    misses the owner that matters most here: `twes_owner` is neither, and it owns the policed tables, so
     *    it is exempt from their policies wherever `FORCE ROW LEVEL SECURITY` is absent. The question asked is
     *    therefore whether the owner is a role this connection could **already become**. Note too that a
     *    function's default ACL grants `EXECUTE` to **PUBLIC**, so an untouched `SECURITY DEFINER` function is
     *    callable by every role on the server.
     *
     * Round 7 demonstrated all three, reading *and writing* across tenants with the verdict CLEAN — and the
     * leaking topology was this project's own provisioned fixture plus one `CREATE VIEW`, because `twes_bypass`
     * already exists and is the natural home somebody would put cross-tenant reporting in.
     *
     * Note `FORCE ROW LEVEL SECURITY` genuinely saves the case where the owner is merely the *table* owner, so
     * the existing design reasoning holds; the gap was that no question was asked about the OBJECTS a
     * connection may read, only about the ROLES it may become.
     *
     * Scoped to non-system schemas, necessarily: every one of PostgreSQL's ~150 catalogue and
     * `information_schema` views is owned by a superuser with `security_invoker` unset, so an unscoped check
     * would refuse every connection on earth.
     *
     * @throws \RuntimeException if such an object is readable
     */
    public static function assertNoRlsExemptObjectIsReadable(\PDO $connection): void
    {
        $statement = $connection->query(
            'SELECT n.nspname || \'.\' || c.relname AS "object", '
            . 'c.relkind::text AS kind, o.rolname AS owner, '
            . 'o.rolsuper OR o.rolbypassrls AS owner_exempt, '
            // Compared to the literal 'true' IN SQL, so PHP receives a real boolean. `pg_options_to_table`
            // yields the *string* 'true', which isTrue() does not recognise — it accepts `t` and `1`, the
            // spellings pdo_pgsql produces for an actual boolean column. Normalising here rather than
            // widening isTrue() keeps that helper's contract to what the driver emits: the first version of
            // this check read every security_invoker view as unsafe because of exactly this mismatch.
            . "coalesce((SELECT option_value FROM pg_options_to_table(c.reloptions) "
            . "WHERE option_name = 'security_invoker'), 'false') = 'true' AS security_invoker "
            . 'FROM pg_class c '
            . 'JOIN pg_roles o ON o.oid = c.relowner '
            . 'JOIN pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE c.relkind IN ('v', 'm', 'f') "
            . "AND n.nspname NOT IN ('pg_catalog', 'information_schema') "
            // Only objects this connection can actually read — a view it cannot select from leaks nothing.
            //
            // By REACHABILITY, not by `has_table_privilege`. Round 11 found this line still using that
            // function, which reintroduced the exact `WITH INHERIT FALSE` gap this file documents at length
            // and closed in the table check seven rounds earlier: a SELECT grant held non-inheritably is
            // invisible to it, so a leaking view was excluded from the result set and the verdict read clean.
            // Owner-reachability is an arm of its own because a NULL `relacl` means "owner only", and if the
            // connection can become the owner it can read the view regardless of any grant.
            . 'AND (' . self::roleIsReachableSql('c.relowner')
            . ' OR ' . self::anyAccessIsReachableSql() . ') '
            . 'ORDER BY 1',
        );

        if (false === $statement) {
            throw new \RuntimeException('Could not inspect views and materialised views.');
        }

        /** @var list<array{object: string, kind: string, owner: string, owner_exempt: bool|string, security_invoker: bool|string}> $objects */
        $objects = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $violations = self::rlsExemptObjectViolations($objects);

        // SECURITY DEFINER functions, asked separately because they live in pg_proc rather than pg_class.
        //
        // THE OWNER FILTER IS REACHABILITY, NOT `rolsuper OR rolbypassrls`. Round 11 found this restricted to
        // exempt owners, and that misses the owner that matters most in this very project: `twes_owner` is
        // neither a superuser nor `BYPASSRLS`, and it **owns the policed tables** — so unless every one of them
        // carries `FORCE ROW LEVEL SECURITY` it is exempt from its own policies, and a SECURITY DEFINER
        // function owned by it hands the caller that exemption. The correct question is not "is this owner
        // privileged" but "does calling this function run as a role this connection could not otherwise
        // become". If the owner IS reachable, the call is no escalation — the connection could `SET ROLE` to it
        // anyway, and that reachability is itself reported by assertPolicedTablesAreBeyondThisRolesReach().
        $functions = $connection->query(
            'SELECT n.nspname || \'.\' || p.proname AS "function", o.rolname AS owner, '
            . 'o.rolsuper OR o.rolbypassrls AS owner_exempt '
            . 'FROM pg_proc p '
            . 'JOIN pg_roles o ON o.oid = p.proowner '
            . 'JOIN pg_namespace n ON n.oid = p.pronamespace '
            . 'WHERE p.prosecdef '
            . "AND n.nspname NOT IN ('pg_catalog', 'information_schema') "
            . 'AND NOT ' . self::roleIsReachableSql('p.proowner') . ' '
            // EXECUTE resolved through the ACL, with the default-grants-PUBLIC arm that
            // `has_function_privilege` was silently supplying. Dropping that arm along with the function would
            // have made every untouched SECURITY DEFINER function invisible — the commonest case there is.
            . 'AND ' . self::privilegeIsReachableSql('p.proacl', 'EXECUTE', true) . ' '
            . 'ORDER BY 1',
        );

        if (false !== $functions) {
            /** @var list<array{function: string, owner: string, owner_exempt: bool|string}> $rows */
            $rows = $functions->fetchAll(\PDO::FETCH_ASSOC);

            $violations = [...$violations, ...self::securityDefinerFunctionViolations($rows)];
        }

        if ([] !== $violations) {
            throw new \RuntimeException(
                'This connection can read an object that borrows a privileged role\'s exemption from '
                . 'row-level security, so the policies on the underlying tables do not apply to it: '
                . implode('; ', $violations)
                . '. A view over tenant-owned data must be created WITH (security_invoker = true) and owned '
                . 'by a role that is itself subject to the policies; a materialised view over tenant-owned '
                . 'data cannot be made safe at all, because a matview carries no row-level security; and a '
                . 'SECURITY DEFINER function must be owned by a role this connection could already become, '
                . 'or made SECURITY INVOKER, or have EXECUTE revoked from PUBLIC.',
            );
        }
    }

    /**
     * Which readable `SECURITY DEFINER` functions hand this connection a role it could not otherwise become.
     *
     * Pure, for the same reason the two sibling `*Violations()` methods are: arranging a privileged owner needs
     * privileges the runtime role must never hold, so the branches are unit-testable here and separately
     * proven live against the real catalogue by the integration suite.
     *
     * The message distinguishes the two reasons, because the remedies differ. An **exempt** owner (superuser or
     * `BYPASSRLS`) means row security never applies inside the call at all. A merely **unreachable** owner
     * means the call runs as somebody else — which is a cross-tenant read whenever that somebody is the
     * tables' owner and the tables are not `FORCE`d, and is an unaudited privilege transfer regardless.
     *
     * @param list<array{function: string, owner: string, owner_exempt: bool|string}> $functions
     *
     * @return list<string>
     */
    public static function securityDefinerFunctionViolations(array $functions): array
    {
        $violations = [];

        foreach ($functions as $function) {
            $violations[] = \sprintf(
                '%s is SECURITY DEFINER and owned by %s, %s, so calling it runs as that role',
                $function['function'],
                $function['owner'],
                self::isTrue($function['owner_exempt'])
                    ? 'which is EXEMPT from row-level security (superuser or BYPASSRLS)'
                    : 'a role this connection cannot otherwise become — and if that role owns the policed '
                        . 'tables without FORCE ROW LEVEL SECURITY it is exempt from their policies',
            );
        }

        return $violations;
    }

    /**
     * Which readable views, materialised views or foreign tables borrow an exemption.
     *
     * Pure, so the branches are testable without arranging a privileged owner — the same reason
     * {@see self::roleCanBypassPolicies()} and {@see self::policedTableViolations()} are.
     *
     * @param list<array{object: string, kind: string, owner: string, owner_exempt: bool|string, security_invoker: bool|string}> $objects
     *
     * @return list<string>
     */
    public static function rlsExemptObjectViolations(array $objects): array
    {
        $violations = [];

        foreach ($objects as $object) {
            // A MATERIALISED VIEW or a FOREIGN TABLE cannot carry row-level security at all, at any
            // ownership, so both are refused on kind alone. A matview is a stored copy of rows somebody could
            // read; a foreign table's rows live somewhere this server does not police.
            if ('m' === $object['kind'] || 'f' === $object['kind']) {
                $violations[] = \sprintf(
                    '%s is %s, which cannot carry row-level security at all%s',
                    $object['object'],
                    'm' === $object['kind'] ? 'a materialised view' : 'a foreign table',
                    'm' === $object['kind']
                        ? ' — it is a plaintext snapshot of whatever the refreshing role could read'
                        : ' — its rows are not policed by this server',
                );

                continue;
            }

            // A VIEW is judged on `security_invoker` FIRST, and ownership only sharpens the message.
            //
            // The precedence matters and getting it backwards produces a false positive: with
            // `security_invoker = true` the view evaluates policies as the QUERYING role, so the owner's own
            // privileges are irrelevant and the view is safe even when a superuser owns it. The first version
            // of this check tested ownership first and refused exactly that safe shape.
            if (self::isTrue($object['security_invoker'])) {
                continue;
            }

            $violations[] = \sprintf(
                '%s is a view without security_invoker, so it evaluates row-level security as its owner %s '
                . 'rather than as the querying role%s',
                $object['object'],
                $object['owner'],
                self::isTrue($object['owner_exempt'])
                    ? ' — and that owner is EXEMPT from row-level security (superuser or BYPASSRLS), so the '
                        . 'view returns and accepts every tenant'
                    : ', so it returns that role\'s tenant scope and not the caller\'s',
            );
        }

        return $violations;
    }

    /**
     * Refuse a connection carrying tenant data MATERIALISED at session lifetime.
     *
     * **The seventh bypass class, and the first one that is not about privileges at all.** Every other guard
     * here is transaction-shaped because {@see self::bind()} is: `set_config(..., true)` is undone on COMMIT,
     * which is what stops a binding reaching whoever gets the connection next. Two ordinary PostgreSQL
     * features are session-shaped instead, and both copy rows out from under a policy that is correctly in
     * force at the time:
     *
     *  - a **TEMPORARY TABLE** lives until the session ends, carries no row-level security of its own, and is
     *    in no policed inheritance hierarchy — so no arm of
     *    {@see self::assertPolicedTablesAreBeyondThisRolesReach()} can ever see it, by construction;
     *  - a **`DECLARE … CURSOR WITH HOLD`** is materialised by PostgreSQL at COMMIT *precisely so that it can
     *    be read afterwards*, and `pg_cursors` makes it discoverable to the next holder of the connection.
     *
     * Neither needs a privilege the restricted runtime role does not already have, and both are what an
     * ordinary reporting job or batch import writes. Round 11 read tenant A's rows while bound to tenant B
     * with all four other guards reporting clean.
     *
     * **This is a detection, and the remedy is {@see self::discardSessionState()} at release.** The obligation
     * is recorded rather than wired because no connection-pool lifecycle exists yet — the same gap R4-3 names,
     * and it wants the same hook. Wave 1 owes both calls: `discardSessionState()` when a connection is
     * returned, and this assertion when one is acquired. Landing a pool without them is a
     * completeness-reviewer P0.
     *
     * `pg_my_temp_schema()` rather than a `pg_temp%` name match: every *other* session's temporary relations
     * are visible in `pg_class` too, and reporting those would make this refuse connections over state it
     * cannot reach and cannot clear. It returns 0 when this session has no temporary schema.
     *
     * Only **holdable** cursors are reported. A cursor without `WITH HOLD` is closed by PostgreSQL at COMMIT,
     * so it cannot outlive the binding it was declared under and is not a cross-tenant channel.
     *
     * @throws \RuntimeException if any such object exists
     */
    public static function assertNoSessionLifetimeDataIsMaterialised(\PDO $connection): void
    {
        $relations = $connection->query(
            'SELECT c.relname AS name, c.relkind::text AS kind FROM pg_class c '
            . 'WHERE pg_my_temp_schema() <> 0 AND c.relnamespace = pg_my_temp_schema() '
            . 'ORDER BY 1',
        );

        if (false === $relations) {
            throw new \RuntimeException('Could not inspect this session\'s temporary relations.');
        }

        $cursors = $connection->query('SELECT name FROM pg_cursors WHERE is_holdable ORDER BY 1');

        if (false === $cursors) {
            throw new \RuntimeException('Could not inspect this session\'s held cursors.');
        }

        /** @var list<array{name: string, kind: string}> $relationRows */
        $relationRows = $relations->fetchAll(\PDO::FETCH_ASSOC);
        /** @var list<array{name: string}> $cursorRows */
        $cursorRows = $cursors->fetchAll(\PDO::FETCH_ASSOC);

        $violations = self::sessionLifetimeDataViolations($relationRows, $cursorRows);

        if ([] !== $violations) {
            throw new \RuntimeException(
                'This connection carries tenant data materialised at SESSION lifetime, which outlives the '
                . 'transaction-scoped binding and is therefore readable under whatever tenant is bound next: '
                . implode('; ', $violations)
                . '. Call discardSessionState() when returning a connection to the pool.',
            );
        }
    }

    /**
     * Which session-lifetime objects are a cross-tenant channel, given the catalogue rows.
     *
     * Pure, for the same reason the sibling `*Violations()` methods are — and here the reason is sharper: the
     * kinds enumerated below cannot all be arranged on one connection cheaply (a temporary *sequence* needs a
     * separate statement, a temporary *view* is legal but rare), so the classification is proven exhaustively
     * here and the dangerous pair is proven live by the integration suite.
     *
     * @param list<array{name: string, kind: string}> $relations
     * @param list<array{name: string}> $cursors
     *
     * @return list<string>
     */
    public static function sessionLifetimeDataViolations(array $relations, array $cursors): array
    {
        $violations = [];

        foreach ($relations as $relation) {
            $violations[] = \sprintf(
                'the temporary %s %s holds rows copied out from under the policies, and carries none of its '
                . 'own',
                match ($relation['kind']) {
                    'r' => 'table',
                    'v' => 'view',
                    'S' => 'sequence',
                    'm' => 'materialised view',
                    'c' => 'composite type',
                    'i' => 'index',
                    default => 'relation of kind ' . $relation['kind'],
                },
                $relation['name'],
            );
        }

        foreach ($cursors as $cursor) {
            $violations[] = \sprintf(
                'the held cursor %s was materialised at COMMIT and can be FETCHed under any later binding',
                $cursor['name'],
            );
        }

        return $violations;
    }

    /**
     * Clear every scrap of session-lifetime state from a connection, so it can be reused safely.
     *
     * `DISCARD ALL` rather than a targeted `CLOSE ALL` plus a `DROP` sweep, and the choice is deliberate: it
     * also drops the session's temporary schema, resets every `SET` — including this class's own tenant GUC —
     * releases sequence state and deallocates prepared statements. A targeted version would need extending
     * every time PostgreSQL grows another kind of session state, and forgetting to extend it is silent.
     *
     * Refused inside a transaction because PostgreSQL raises 25001 there. Refusing with an explanation is the
     * difference between a caller who moves the call and a caller who wraps it in a try/catch — and the second
     * is how a release-time guard quietly stops running.
     *
     * @throws \RuntimeException if called inside a transaction
     */
    public static function discardSessionState(\PDO $connection): void
    {
        if ($connection->inTransaction()) {
            throw new \RuntimeException(
                'Discard session state outside a transaction: DISCARD ALL cannot run inside a transaction '
                . 'block (SQLSTATE 25001). Commit or roll back first, then discard.',
            );
        }

        $connection->exec('DISCARD ALL');
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

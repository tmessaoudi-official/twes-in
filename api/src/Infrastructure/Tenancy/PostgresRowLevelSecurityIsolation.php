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

use Twes\Infrastructure\Tenancy\Exception\ConnectionMustBeEvicted;
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
     * The column every policed table scopes by — ONE convention per database, not one per policy.
     *
     * **This exists because the canonicality check was CIRCULAR, and round 14 proved it with a plain text
     * column.** {@see self::policyExpressionColumn()} extracts whatever identifier a policy names and then
     * compares the expression to {@see self::canonicalPolicyExpression()} built from *that same identifier*, so
     * the comparison always agreed with itself: a policy reading `label = current_setting('twes.tenant_id')`
     * was certified as "the canonical tenant predicate". [Verified: `policyExpressionIsCanonical()` returns true
     * for `company_id`, `id`, `tenant_id` AND `label`.] A policy scoping the wrong column leaves the table
     * unscoped by tenant while every existing check reports clean, and a cross-tenant INSERT follows.
     *
     * Round 7 closed "one column per TABLE"; this closes "which column". Both were the same underlying mistake
     * — treating the column as a free variable read out of the data being validated rather than as a fact known
     * independently of it. A control cannot derive its own expected value from its input.
     *
     * **This narrows {@see self::policySqlFor()}'s contract, deliberately.** That method still takes a
     * `$tenantColumn` — tests need it to EMIT a non-canonical policy and prove detection fires — but a policy
     * naming anything other than this constant is now a violation. `docs/plans/build-waves.plan.md` § Wave 1
     * already rules that every tenant-owned table carries `company_id` with `PRIMARY KEY (company_id, id)`, so
     * the flexibility was never a product requirement; it was the hole.
     */
    public const string TENANT_COLUMN = 'company_id';

    /**
     * The large-object WRITE entry points. A `public const` rather than a local array, so a test can generate
     * one case per entry instead of pinning one instance of the rule.
     *
     * Writes only. `lo_get`/`loread` are reads, and a connection that cannot create a large object has nothing
     * of its own to read — while revoking the readers too would break `pg_dump`, which is the kind of collateral
     * damage that gets a control reverted wholesale.
     *
     * **A name list's real failure mode is a TYPO, and nothing caught it:** an entry misspelled here matches no
     * `pg_proc` row, `bool_or` over an empty set returns NULL, and the writer is silently never checked while
     * the gate reports clean. Round 14 found four of the five entries individually deletable with the test
     * green, which is the same hole seen from the other side. `LargeObjectWritersTest` now asserts every entry
     * resolves to a real `pg_catalog` function.
     *
     * Note `lo_import` ships with a non-NULL `proacl` (`{postgres=X/postgres}`) so PUBLIC never held it, unlike
     * the other five. It stays here anyway — a cluster where somebody granted it is exactly what a detector is
     * for — which is why this list is deliberately LONGER than the `REVOKE` set in `infra/README.md`.
     *
     * @var list<string>
     */
    public const array LARGE_OBJECT_WRITERS = [
        // `lo_creat` — the LEGACY spelling, and the one round 15 found MISSING from both this list and the
        // `REVOKE` block in `infra/README.md`. Two letters short of `lo_create` and easy to read past, and it is
        // the one `PDO::pgsqlLOBCreate()` reaches through libpq — so it is the API an application would actually
        // use, not an exotic path. With it absent the remedy did not remove the capability AND the detector could
        // not see it, so a connection created a large object and the acquisition check then refused every
        // subsequent acquisition until a privileged role unlinked it: precisely the outage the remedy exists to
        // prevent. [Verified: `lo_creat(integer)` has a NULL `proacl` and `has_function_privilege('twes', …)` is
        // true on an untouched cluster.]
        'lo_creat',
        'lo_create',
        'lo_from_bytea',
        'lo_import',
        'lo_put',
        'lowrite',
    ];

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
     * **`$tenantColumn` MUST be {@see self::TENANT_COLUMN}.** It is a parameter only so a test can emit a
     * NON-canonical policy and prove detection fires; {@see self::policedTableViolations()} refuses any other
     * column, because the canonicality check used to build its expectation from the identifier it read out of
     * the policy under test and therefore agreed with itself for `label` as readily as for `company_id`. Round
     * 15 noted the constraint was documented on the constant and enforced in the checker but absent HERE, which
     * is the docblock a migration author actually reads.
     *
     * @return list<string> statements to run in order, in a migration
     */
    public static function policySqlFor(string $table, string $tenantColumn = self::TENANT_COLUMN): array
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

        // THE SEVENTH AND EIGHTH CARRIERS, composed here rather than left as methods a caller must remember.
        // Round 12 found the seventh-class guard reachable only from its own test, one round after it closed a
        // P0 — while this method already composed the equally lifecycle-dependent
        // assertNoTenantPinnedOnTheConnection(). Two entry points to remember is one more than a pool wiring
        // will remember, and this project's own fifth-path test asserts exactly this property: "a check nobody
        // calls is not a check".
        //
        // assertConnectionCannotCreateTemporaryObjects() is deliberately NOT here — see its docblock: the test
        // database grants TEMPORARY on purpose, so composing it would fail every run. That one is addressed to
        // production, recorded in infra/README.md, and owed as Wave 1 wiring.
        self::assertNoSessionLifetimeDataIsMaterialised($connection);
        self::assertNoLargeObjectIsReachable($connection);

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
     * **A second documented boundary, added at round 13: every connection can read every other connection's
     * in-flight SQL.** `pg_stat_activity` exposes `query` to the *same role* with no `pg_read_all_stats`
     * membership required — and every request connects as the same runtime role, so one tenant's request can
     * read the statement text of another's.
     *
     * **The round-13 citation here was FALSE and is replaced** (round 14): it claimed one connection saw the
     * other's `set_config('twes.tenant_id', '<uuid>', true)` verbatim. It cannot — PDO defaults to server-side
     * prepares, so `bind()`'s parameters never enter statement text and a spy sees `DEALLOCATE pdo_stmt_…`. That
     * citation also contradicted this very docblock's own rule below, which says the domain binds parameters
     * rather than building SQL; the two could not both be true, and the wrong one was the evidence.
     * [Verified at round 14: an INTERPOLATED literal is fully visible — a second `twes` connection read
     * `SELECT pg_sleep(2) WHERE 'client-dupont@example.com' <> 'x'` in full from `pg_stat_activity`.]
     *
     * So the boundary is real and the threat is precise: rows do not cross, and neither do bound parameters.
     * STATEMENT TEXT does — every literal somebody interpolates instead of binding: a client-name search, an
     * `IN (…)` of invoice numbers, an e-mail in a filter.
     *
     * Not removable for a shared role, which is why it belongs here as a documented scope rather than as an
     * assertion. Two consequences that ARE actionable and are recorded in `infra/README.md`:
     * **`application_name` must never carry tenant identity**, and **no statement may interpolate personal
     * data** — which the domain already enforces by binding parameters rather than building SQL.
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
            . self::roleIsReachableSql('c.relowner') . ' AS owner_reachable, '
            // TRUNCATE by REACHABILITY, not by inheritance. `has_table_privilege` resolves privileges the
            // way PostgreSQL applies them *now* — inheritably — while `SET ROLE` is authorised by
            // MEMBERSHIP. A grant made `WITH INHERIT FALSE` (the PG16+ way to say "hold this deliberately,
            // not by default") is therefore invisible to has_table_privilege and one statement away from
            // the privilege. Round 5 erased both tenants through exactly that gap. aclexplode is used
            // because it exposes the grantee, which is the thing membership has to be tested against;
            // grantee 0 is PUBLIC.
            . self::privilegeIsReachableSql('c.relacl', 'TRUNCATE', false) . ' AS can_truncate, '
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
     * ONE definition, referenced everywhere — and that sentence was FALSE for a round after it was written.
     * Round 12 found `assertPolicedTablesAreBeyondThisRolesReach()` still spelling both predicates out inline,
     * in the very query these helpers were extracted from, while this docblock told a reader there was only one
     * place to change. Byte-equivalent at the time, so nothing leaked — but divergence between copies is the
     * defect this file records twice (round 5 fixed one copy of this predicate; round 11 found the other, seven
     * rounds later), and a claim of a single choke point is what stops somebody looking for the second copy.
     * Both call sites now call these. `has_table_privilege`/`has_function_privilege` resolve privileges the way PostgreSQL applies
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
     * The NARROW form: "this connection can actually BECOME the role", for use under a negation.
     *
     * `MEMBER` is the widest of `pg_has_role`'s modes and is the correct one everywhere this class asks
     * *positively* — "could this connection reach a dangerous privilege" must err wide. Under a **negation** the
     * same width inverts the safety direction, and round 12 found exactly that in the `SECURITY DEFINER`
     * filter: `NOT (… 'MEMBER' …)` excludes a function whose owner the connection is a *member* of but cannot
     * `SET ROLE` to — which is precisely the case where calling the function is the ONLY route to that role's
     * rights, and therefore the one that most needs flagging.
     *
     * PostgreSQL 16 separated the three grant options, so this is a live distinction rather than a theoretical
     * one. [Verified on this server, for this fixture's own `GRANT twes_truncator TO twes WITH INHERIT FALSE`:
     * `MEMBER` true, `USAGE` false, `SET` true, and `pg_auth_members` records `inherit_option=false`,
     * `set_option=true` as separate columns.]
     *
     * `SET` is the mode asked here because `SET ROLE` is what "become" means. A grant made `WITH SET FALSE`
     * yields `MEMBER` true and `SET` false: the connection cannot become the role by any ordinary means, so a
     * `SECURITY DEFINER` function owned by it IS an escalation and must be reported.
     */
    /**
     * Whether a `proconfig` array pins the tenant setting, as an SQL expression.
     *
     * Built from {@see self::TENANT_SETTING} rather than written out, for the reason this class keeps
     * relearning: a second spelling of the setting name is a second thing to keep in step, and the policy
     * expression, `bind()` and this check must all mean the same GUC. `proconfig` entries are `name=value`,
     * The entry's NAME half is compared for EQUALITY after `lower()`, not with a `LIKE` over the whole entry —
     * this docblock described a `LIKE` and a `twes.tenant_id=%` prefix that the code has not used since round 13,
     * and contradicted the inline comment eight lines below it (round 15 found both halves).
     */
    private static function tenantSettingIsPinnedSql(string $proconfig): string
    {
        // The NAME HALF, lower-cased, compared for EQUALITY — not a `LIKE` over the whole entry.
        //
        // Round 13 defeated the first version with one keystroke: `SET "TWES.TENANT_ID" = '<tenant>'` stores
        // `proconfig = {TWES.TENANT_ID=...}` VERBATIM, because PostgreSQL normalises a custom GUC's name only
        // when a placeholder for it already exists in that backend. At call time the GUC is resolved
        // CASE-INSENSITIVELY, so the function pins the very parameter the policy reads — while a
        // case-sensitive `LIKE 'twes.tenant_id=%'` misses it entirely. [Verified: proconfig stored as
        // `TWES.TENANT_ID=...`, the old pattern matched false, the name-half comparison matches true.] The
        // attacker needs no extra privilege for the quoted spelling: `pg_parameter_acl` lowercases its keys.
        //
        // Lower-casing only the NAME is deliberate. `lower(cfg)` over the whole entry would also lower-case an
        // attacker-controlled VALUE — harmless for a UUID, wrong in principle, and the kind of shortcut that
        // becomes a bug when the next GUC's value is case-significant.
        return \sprintf(
            'EXISTS (SELECT 1 FROM unnest(coalesce(%s, \'{}\'::text[])) AS cfg '
            . 'WHERE lower(split_part(cfg, \'=\', 1)) = %s)',
            $proconfig,
            "'" . self::TENANT_SETTING . "'",
        );
    }

    private static function roleCanBeAssumedSql(string $roleOid): string
    {
        return \sprintf(
            "(pg_has_role(session_user, %s, 'SET') OR pg_has_role(current_user, %s, 'SET'))",
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
     * **Four of PostgreSQL 18's EIGHT relation privileges, with every exclusion argued** — round 13 pointed
     * out that the previous version named two and left six unmentioned, in a class whose whole style is
     * exhaustive enumeration with a stated reason per exclusion.
     *
     * Two counting errors of round 13's own, both found at round 14 and both corrected in place rather than
     * annotated below — in a docblock whose security argument RESTS on exhaustive enumeration, a completeness
     * claim that is not complete is the specific failure mode this file records for `PriceCalculator`:
     *
     *   - it said "twelve" and then listed **thirteen** identifiers. The relevant number is neither: a RELATION
     *     has exactly **eight** privileges — `SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER,
     *     MAINTAIN` — four checked below and four argued away. The other five in that list belong to databases,
     *     schemas, functions and languages, so they can never appear in a `relacl` at all.
     *   - it called that list "the full set `aclexplode` can yield", which it is not: `aclexplode` also yields
     *     `SET` and `ALTER SYSTEM` (parameter ACLs). [Verified on PostgreSQL 18.4:
     *     `SELECT (aclexplode('{twes=sA/postgres}'::aclitem[])).privilege_type` returns `SET` and
     *     `ALTER SYSTEM`.] The list is right for a `relacl`, which is all this method reads; the "full set"
     *     wording was the overreach.
     *
     * Excluded, and why:
     *
     *  - `TRUNCATE` — does not reach through a view; the policed tables' own TRUNCATE reachability is asserted
     *    separately by {@see self::assertPolicedTablesAreBeyondThisRolesReach()}.
     *  - `REFERENCES` — creates a foreign key, which reads nothing through the view.
     *  - `TRIGGER` — creating a trigger needs a companion DML grant, which is already in the set above. (Note
     *    the separate matter of a trigger FUNCTION firing without EXECUTE, closed in the function query.)
     *  - `MAINTAIN` (PG17+) — grants `REFRESH MATERIALIZED VIEW` without `SELECT`, so it can refresh a matview's
     *    contents but not read them. A matview is refused on KIND alone here, so it is covered already.
     *  - `CONNECT`, `TEMPORARY` — database privileges, not relation ones; `TEMPORARY` is handled by
     *    {@see self::assertConnectionCannotCreateTemporaryObjects()}.
     *  - `CREATE`, `USAGE`, `EXECUTE` — schema and routine privileges; `EXECUTE` is handled by the function
     *    query, and the runtime role holds `CREATE` nowhere.
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
    public static function canonicalPolicyExpression(string $tenantColumn = self::TENANT_COLUMN): string
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
                        . "descendants' own policies are. Police the parent too -- AND check its other "
                        . 'descendants: an unpoliced SIBLING of the policed table is not reported here, '
                        . 'because reaching a policed table\'s rows through another relation requires '
                        . 'ancestry and a sibling has none. It is still an unpoliced tenant-owned table, and '
                        . 'the schema gate is what finds it',
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

            // AND THE COLUMN MUST BE THE TENANT COLUMN. Everything above asks whether the policy is
            // self-consistent; none of it asks whether the column it scopes has anything to do with tenancy.
            // Because the expected expression is built from the identifier read out of the policy itself, the
            // comparison is circular and `label = current_setting('twes.tenant_id')` passed as canonical —
            // leaving the table unscoped by tenant with every check reporting clean, and a cross-tenant INSERT
            // one statement away. Anchored to {@see self::TENANT_COLUMN}, which is known independently of the
            // input rather than derived from it.
            foreach ($distinct as $column) {
                if (self::TENANT_COLUMN === $column) {
                    continue;
                }

                $violations[] = \sprintf(
                    '%s has a policy scoping %s, which is not the tenant column (%s). The predicate is '
                    . 'well-formed and self-consistent, which is exactly what makes it dangerous: the table is '
                    . 'not scoped by tenant at all while every other check reports clean, so a row can be '
                    . 'written into another tenant. One tenant column per database, not one per policy',
                    $table['table'],
                    $column,
                    self::TENANT_COLUMN,
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
            // Whether the OWNER IS THE QUERYING ROLE ITSELF. For such a view, "evaluate RLS as the owner" IS
            // "evaluate RLS as the caller", so the view is safe and reporting it is a false positive — and a
            // check that fires on a safe shape is the argument this class itself makes for not asserting
            // cross-database CONNECT. Latent today (the runtime role holds no CREATE anywhere, so it cannot
            // create one), which is exactly why it needs stating rather than discovering later.
            // Looked up in `pg_roles` rather than cast through `regrole`. `regrolein` DOWNCASES an unquoted
            // name and `current_user` renders unquoted, so a deployment whose runtime role is
            // `CREATE ROLE "twesApp"` made this query raise `role "twesapp" does not exist` — and the whole
            // method then failed with "Could not inspect views and materialised views", a total outage traced
            // to entirely the wrong cause. [Verified: `'POSTGRES'::regrole` resolves to `postgres`, while
            // `'"POSTGRES"'::regrole` errors.] Fail-closed, but for a reason nobody could find.
            . '(SELECT r.oid FROM pg_roles r WHERE r.rolname = current_user) = c.relowner AS owned_by_caller, '
            // Compared to the literal 'true' IN SQL, so PHP receives a real boolean. `pg_options_to_table`
            // yields the *string* 'true', which isTrue() does not recognise — it accepts `t` and `1`, the
            // spellings pdo_pgsql produces for an actual boolean column. Normalising here rather than
            // widening isTrue() keeps that helper's contract to what the driver emits: the first version of
            // this check read every security_invoker view as unsafe because of exactly this mismatch.
            // CAST, NOT A STRING COMPARISON. PostgreSQL stores a boolean reloption VERBATIM as the user wrote
            // it, so `security_invoker = on` and `security_invoker = 1` are both valid and both make the view
            // evaluate policies as the invoker — while `= 'true'` reports them as unsafe. Round 14 found it: a
            // SAFE view refused, with a message stating "the view returns and accepts every tenant", which is
            // a false statement about the object. Same class as the `current_user::regrole` outage closed the
            // round before — a total refusal traced to entirely the wrong cause. `::boolean` accepts every
            // spelling PostgreSQL does (`on/off`, `true/false`, `1/0`, `yes/no`), and the comparison stays in
            // SQL so PHP still receives a real boolean.
            . "coalesce((SELECT option_value FROM pg_options_to_table(c.reloptions) "
            . "WHERE option_name = 'security_invoker'), 'false')::boolean AS security_invoker, "
            // Whether this relation carries a USER rule. `_RETURN` is the rule PostgreSQL creates for every
            // view's own body, so excluding it by name is what separates "a view" from "a table somebody
            // attached a DO INSTEAD rule to".
            . "EXISTS (SELECT 1 FROM pg_rewrite w WHERE w.ev_class = c.oid AND w.rulename <> '_RETURN') "
            . 'AS has_user_rule '
            . 'FROM pg_class c '
            . 'JOIN pg_roles o ON o.oid = c.relowner '
            . 'JOIN pg_namespace n ON n.oid = c.relnamespace '
            // **KINDS `'r'` AND `'p'` TOO WHEN THEY CARRY A USER RULE — the ELEVENTH carrier** (round 15 P0).
            // This method exists because "PostgreSQL will happily execute part of a query as a role you cannot
            // reach". A VIEW is one way to arrange that; a **RULE** is the other, it lives in the same
            // `pg_rewrite` catalogue, and it reaches it through an ordinary TABLE. PostgreSQL sets `checkAsUser`
            // on the rewritten query to the rule's HOST relation owner exactly as it does for a view body, so
            // `check_enable_rls()` returns `RLS_NONE` when that owner is exempt and every policy is SKIPPED
            // rather than evaluated as somebody else.
            //
            // A table was in NEITHER query's subject set: `relrowsecurity` is false so the policed-table check
            // never sees it, it has no inheritance edge, and its kind was not in the list here. [Verified: a
            // `DO INSTEAD` rule on a gateway table owned by an exempt role gave a tenant-B-bound connection both
            // a cross-tenant READ (through the outer RETURNING) and a cross-tenant WRITE — a row planted
            // carrying tenant A's `company_id` — while this check reported CLEAN. Control: re-owning the gateway
            // to a non-exempt role leaks nothing, which is the same precondition the view arm already refuses.]
            //
            // Filtered on the rule's EXISTENCE rather than widening the kinds outright, because otherwise every
            // tenant-owned table in the database would enter this query and be judged by view rules that do not
            // apply to it.
            . "WHERE (c.relkind IN ('v', 'm', 'f') "
            . "OR (c.relkind IN ('r', 'p') "
            . "AND EXISTS (SELECT 1 FROM pg_rewrite w2 WHERE w2.ev_class = c.oid AND w2.rulename <> '_RETURN'))) "
            // PERMANENT only. Every session's temporary relations are visible in `pg_class` to every other
            // session, and `rlsExemptObjectViolations()` refuses kind `'m'` on kind ALONE — before the
            // `owned_by_caller` exclusion — so ONE connection creating a `pg_temp` materialised view made
            // every OTHER acquisition throw, naming an object in another backend's temp schema that it can
            // neither read nor drop. A whole-pool outage triggered by any single connection, needing only the
            // TEMPORARY grant the test fixture already holds. [Verified with two live connections: session 2
            // saw session 1's `pg_temp_16` matview with `relnamespace = pg_my_temp_schema()` false.]
            //
            // This is round 12's own finding applied in the third place: the policed-table query got
            // `relpersistence = 'p'` and the session-lifetime check got `pg_my_temp_schema()`, and this query
            // got neither. A tenant-owned view is never temporary; a session's OWN temporary relations are
            // reported by assertNoSessionLifetimeDataIsMaterialised(), which is where they belong.
            . "AND c.relpersistence = 'p' "
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

        /** @var list<array{object: string, kind: string, owner: string, owner_exempt: bool|string, security_invoker: bool|string, has_user_rule: bool|string, owned_by_caller: bool|string}> $objects */
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
            . 'o.rolsuper OR o.rolbypassrls AS owner_exempt, '
            . 'p.prosecdef AS security_definer, '
            . 'coalesce(array_to_string(p.proconfig, \', \'), \'\') AS proconfig '
            . 'FROM pg_proc p '
            . 'JOIN pg_roles o ON o.oid = p.proowner '
            . 'JOIN pg_namespace n ON n.oid = p.pronamespace '
            // `prosecdef` OR a `proconfig` that pins the tenant setting. The second arm is round 12's finding
            // and it is INDEPENDENT of SECURITY DEFINER: PostgreSQL saves and restores GUCs around any call
            // whose `proconfig` is non-null, so a function declared
            // `SET "twes.tenant_id" = '<other tenant>'` scopes the policy to that tenant for the duration of
            // the call while the caller is bound to its own. [Verified on this server: a function with
            // `prosecdef=false` and `proconfig={twes.tenant_id=...}` returned another tenant's row to a
            // connection bound to tenant B, whose direct read of the same table correctly returned 0.]
            //
            // The threat actor is the same as for the leaking view this path exists to refuse — a migration or
            // "support tooling" — and NOT the runtime role: creating one needs `SET` on the parameter, which
            // `twes_owner` does not have (`permission denied to set parameter "twes.tenant_id"`, and
            // `has_parameter_privilege('twes_owner', 'twes.tenant_id', 'SET')` is false). But a
            // `GRANT SET ON PARAMETER` is an entirely ordinary thing for somebody to hand migration tooling,
            // and once such a function exists any role holding EXECUTE calls it forever. That is exactly the
            // persistent delegated bypass this method exists to detect.
            . 'WHERE (p.prosecdef OR ' . self::tenantSettingIsPinnedSql('p.proconfig') . ')'
            . "AND n.nspname NOT IN ('pg_catalog', 'information_schema') "
            // NOT ANOTHER BACKEND'S TEMP SCHEMA — round 12's finding in its FOURTH place, and the comment on
            // the view query above says so in as many words: "the policed-table query got `relpersistence = 'p'`
            // and the session-lifetime check got `pg_my_temp_schema()`, and this query got neither". That
            // sentence was written about the view query in this same method while the FUNCTION query, twenty
            // lines below it, also had neither. Every session's temporary functions are visible in `pg_proc` to
            // every other session, so one connection creating a temporary SECURITY DEFINER function refused
            // every OTHER acquisition — naming an object the refused connection can neither call, locate nor
            // drop (`DROP` gives `schema "pg_temp" does not exist`). A whole-pool outage triggered by any single
            // connection, needing only the TEMPORARY grant PostgreSQL gives PUBLIC by default.
            //
            // `pg_is_other_temp_schema()` rather than excluding all temp schemas, which is the narrower and
            // therefore correct filter: this connection's OWN temporary functions stay in scope, because a
            // hazard inside this session is this session's problem to refuse. What is excluded is only the
            // objects belonging to a backend this one cannot act on at all.
            . 'AND NOT pg_is_other_temp_schema(n.oid) '
            // The NARROW mode, because this predicate is NEGATED — see roleCanBeAssumedSql(). With 'MEMBER'
            // here, a function whose owner the connection is a member of but cannot SET ROLE to was excluded
            // as "no escalation", when it is the case where the function is the only route to that role.
            // The owner filter applies to the SECURITY DEFINER arm only. A `proconfig` function is dangerous
            // regardless of who owns it — it does not borrow the owner's rights, it rewrites the tenant GUC —
            // so excluding it because its owner is assumable would drop the whole new arm on the floor.
            //
            // **THE `proconfig` DISJUNCT IS WHAT MAKES THAT PARAGRAPH TRUE, and round 14 found it missing — a
            // P0 with a reproduced cross-tenant read.** With only `NOT p.prosecdef OR NOT <owner assumable>`, a
            // function that is BOTH `SECURITY DEFINER` and GUC-pinning, owned by an assumable role, has both
            // disjuncts false and is dropped from the result set. So the comment above described an intent the
            // SQL did not implement, and the failure is INVERTED: adding `SECURITY DEFINER` to a pinning
            // function — strictly more dangerous — is what makes the check stop reporting it.
            // [Verified against a real policed table: with `SECURITY DEFINER`, the acquisition check returns
            // CLEAN while a connection bound to tenant B reads `TENANT-A-SECRET-CLIENT-LIST` through the
            // function and its own direct read correctly returns only `tenant-B-own-row`. Flipping the same
            // function to `SECURITY INVOKER` — less dangerous — makes the check REFUSE it.]
            //
            // The precondition is `GRANT SET ON PARAMETER "twes.tenant_id"`, which migration tooling holds and
            // which round 12 already accepted as sufficient for the `prosecdef = false` case. Nothing about
            // that precondition gets harder when the function also becomes SECURITY DEFINER.
            . 'AND (NOT p.prosecdef OR ' . self::tenantSettingIsPinnedSql('p.proconfig')
            . ' OR NOT ' . self::roleCanBeAssumedSql('p.proowner') . ') '
            // EXECUTE resolved through the ACL, with the default-grants-PUBLIC arm that
            // `has_function_privilege` was silently supplying. Dropping that arm along with the function would
            // have made every untouched SECURITY DEFINER function invisible — the commonest case there is.
            // EXECUTE-reachability **OR** the function is a TRIGGER function — the ninth carrier, and the
            // filter's premise ("a function this connection cannot call leaks nothing") is simply false for
            // one. PostgreSQL checks EXECUTE against the trigger's CREATOR at `CREATE TRIGGER` and performs no
            // ACL check when the trigger FIRES, so a trigger function with EXECUTE revoked from PUBLIC and no
            // grant to the runtime role runs on every INSERT/UPDATE/DELETE that role issues — while the
            // reachability test says false and the row is dropped from the result set. Round 13 demonstrated a
            // cross-tenant exfiltration through exactly that shape, neutralising BOTH the `prosecdef` arm and
            // the `proconfig` arm at once. [Verified: has_function_privilege('twes', fn, 'EXECUTE') is false
            // while the trigger fires under current_user = twes.]
            //
            // `NOT tgisinternal` excludes the triggers PostgreSQL creates for foreign-key and unique
            // constraints, whose functions are `RI_FKey_*` in pg_catalog and already out of scope by schema.
            //
            // **NOT A GAP, and this comment used to claim it was** (corrected round 14). It read: "a function
            // reached through a CHECK constraint, an index expression or a column DEFAULT ... is ACL-checked at
            // DDL time in the same way", i.e. that three more carriers were owed. PostgreSQL enforces EXECUTE at
            // **DML** time for all three, so none of them is a bypass and a guard for them would be dead code.
            // [Verified on PostgreSQL 18.4 as the restricted role, against a SECURITY DEFINER function with
            // EXECUTE revoked from PUBLIC: a column DEFAULT gives `permission denied for function leak_imm` on
            // INSERT, as do a GENERATED ... STORED column and a CHECK constraint.]
            //
            // Worth keeping because it states WHY the trigger is special rather than leaving it as one item in a
            // list: PostgreSQL checks EXECUTE against the trigger's CREATOR at `CREATE TRIGGER` and performs no
            // check when the trigger FIRES. That asymmetry is unique to triggers among these four, and it is the
            // whole reason the arm above exists.
            //
            // Recorded here because the residue as written invited Wave 1 to build a guard for a non-gap — and
            // the first probe I wrote to check it AGREED with the claim, because it supplied a value for the
            // defaulted column so the DEFAULT never evaluated. A test that does not arrive proves nothing.
            . 'AND (' . self::privilegeIsReachableSql('p.proacl', 'EXECUTE', true)
            . ' OR EXISTS (SELECT 1 FROM pg_trigger t WHERE t.tgfoid = p.oid AND NOT t.tgisinternal)'
            // **AND AN EVENT TRIGGER — the TWELFTH carrier, and the trigger arm's exact twin** (round 15 P0).
            // The paragraph above argues that PostgreSQL checks EXECUTE at `CREATE TRIGGER` and performs NO ACL
            // check when the trigger FIRES, so a function with EXECUTE revoked is dropped from the result set
            // while still running under the runtime role's statements. `EventTriggerInvoke()` has the identical
            // property and `pg_event_trigger.evtfoid` was read by nothing — so the argument was made and then
            // applied to one of the two catalogues that carry it.
            //
            // **The `login` event makes this worse than the DML trigger, not merely equal to it** (PostgreSQL
            // 17+): a `ddl_command_end` trigger needs the runtime role to issue DDL, which
            // `assertConnectionCannotCreateTemporaryObjects()` at least constrains in production — but a `login`
            // trigger fires on CONNECT, with no statement from the runtime role at all and no privilege it could
            // be denied. [Verified: a SECURITY DEFINER function owned by `postgres` with EXECUTE revoked from
            // PUBLIC exfiltrated tenant A's rows to a tenant-B-bound session that issued no DDL whatsoever,
            // while this check reported CLEAN.] So no capability guard is a mitigation for it and detection is
            // the only control there is.
            . ' OR EXISTS (SELECT 1 FROM pg_event_trigger e WHERE e.evtfoid = p.oid)'
            // **AND AN AGGREGATE SUPPORT FUNCTION, AND AN OPERATOR-CLASS SUPPORT FUNCTION — the THIRTEENTH
            // carrier** (round 16 P0). Same asymmetry as the two arms above, applied to the remaining two
            // catalogues that carry it: PostgreSQL invokes both through `fmgr` with NO `EXECUTE` check at call
            // time, while `fmgr_security_definer` still honours `prosecdef`. So a `SECURITY DEFINER` function with
            // EXECUTE revoked from PUBLIC, no `pg_trigger` row and no `pg_event_trigger` row was dropped from the
            // result set while running under the runtime role's own statements.
            //
            // [Verified twice, as `twes` bound to tenant B: a direct `SELECT public.leak(1,1)` gives
            // `permission denied for function leak`, while `SELECT public.agg(x) FROM (VALUES (1)) v(x)` — the same
            // function as an aggregate's `SFUNC` — exfiltrated tenant A's rows; and the same function as a btree
            // opclass support function leaked through an index scan under `SET LOCAL enable_seqscan = off`. Both
            // verdicts were CLEAN.]
            //
            // ALL of `pg_aggregate`'s function columns, not just `aggtransfn`: the final, combine, serial,
            // deserial and moving-aggregate variants are each reached the same way, and enumerating one of six
            // would be the same partial closure this arm is fixing. `pg_amproc` covers operator-class support
            // functions; `pg_amop` holds operators rather than functions and `pg_operator.oprcode` IS ACL-checked
            // [Verified: `1 OPERATOR(public.###) 1` gives permission denied], as is `pg_cast.castfunc`, so
            // neither is a carrier and neither is listed.
            . ' OR EXISTS (SELECT 1 FROM pg_aggregate a WHERE p.oid IN ('
            . 'a.aggtransfn, a.aggfinalfn, a.aggcombinefn, a.aggserialfn, a.aggdeserialfn, '
            . 'a.aggmtransfn, a.aggminvtransfn, a.aggmfinalfn))'
            . ' OR EXISTS (SELECT 1 FROM pg_amproc ap WHERE ap.amproc = p.oid)) '
            . 'ORDER BY 1',
        );

        if (false !== $functions) {
            // `proconfig` and `security_definer` were MISSING from this annotation while the classifier reads
            // both — round 15. An incomplete shape on the one call that had no documented shape at all.
            /** @var list<array{function: string, owner: string, owner_exempt: bool|string, security_definer: bool|string, proconfig: string}> $rows */
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
     * A **third** reason was added at round 12 and is independent of ownership entirely: a non-null
     * `proconfig` pinning the tenant setting. PostgreSQL saves and restores GUCs around any such call, so the
     * function scopes the policy to whatever tenant it names for the duration of the call. Naming that one
     * "SECURITY DEFINER" would be false — `prosecdef` is false — and would send a reader to check an ownership
     * chain that is irrelevant.
     *
     *
     * @return bool whether the entry pins it — the `@return list<string>` this carried until round 15 belonged to
     *              `securityDefinerFunctionViolations()` further down, and would have failed `gate:static` on the
     *              day PHPStan lands
     */
    private static function pinsTenantSetting(string $proconfig): bool
    {
        // `proconfig` arrives as a comma-joined list of `name=value` entries. Split, take each name half,
        // lower-case it, compare for equality — the same rule as the SQL arm, so the query and the classifier
        // cannot disagree about what "pins the tenant setting" means.
        foreach (explode(', ', $proconfig) as $entry) {
            if (self::TENANT_SETTING === strtolower(explode('=', $entry, 2)[0])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which readable functions hand this connection a role or a tenant binding it could not otherwise reach.
     *
     * Pure, for the same reason its siblings are: the interesting branches need privileges the runtime role must
     * never hold. **It was the only `*Violations()` method with no docblock and no array shape** (round 15) — and
     * an undocumented shape on a method whose caller reads `proconfig` and `security_definer` is how a `@var`
     * comes to omit the very fields the code uses, which is what that caller's own annotation did.
     *
     * @param list<array{function: string, owner: string, owner_exempt: bool|string, security_definer: bool|string, proconfig: string}> $functions
     *
     * @return list<string> one human-readable violation per problem found, empty when the role is safe
     */
    public static function securityDefinerFunctionViolations(array $functions): array
    {
        $violations = [];

        foreach ($functions as $function) {
            $proconfig = $function['proconfig'] ?? '';

            // The proconfig arm FIRST, because a function can be both and this is the reason that does not
            // depend on who owns it — reporting the ownership reason for a `prosecdef = false` function would
            // be a false statement in an error message.
            // Case-insensitive on the NAME HALF, matching the SQL arm. Round 13 found this `str_contains`
            // carrying the identical defect: had a mixed-case function reached here by ALSO being prosecdef,
            // the classifier would have fallen through to the "is SECURITY DEFINER and owned by %s" branch —
            // a false statement in an error message, which is the exact ordering defect the arm was added to
            // prevent.
            if ('' !== $proconfig && self::pinsTenantSetting($proconfig)) {
                $violations[] = \sprintf(
                    '%s carries a configuration override that pins the tenant setting (%s), so PostgreSQL '
                    . 'scopes the policies to THAT tenant for the duration of the call whatever the caller is '
                    . 'bound to. This is independent of SECURITY DEFINER (%s) and of ownership: the function '
                    . 'does not borrow the owner\'s rights, it rewrites the binding',
                    $function['function'],
                    $proconfig,
                    self::isTrue($function['security_definer'] ?? false) ? 'which it also is' : 'which it is not',
                );

                continue;
            }

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
     * Which readable objects borrow an exemption: views, materialised views, foreign tables — **and, since
     * round 15, tables carrying a rewrite RULE**, which reach the same `pg_rewrite` machinery through an
     * ordinary relation and were the eleventh bypass carrier.
     *
     * Pure, so the branches are testable without arranging a privileged owner — the same reason
     * {@see self::roleCanBypassPolicies()} and {@see self::policedTableViolations()} are.
     *
     * @param list<array{object: string, kind: string, owner: string, owner_exempt: bool|string, security_invoker: bool|string, has_user_rule?: bool|string, owned_by_caller?: bool|string}> $objects
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

            // **A RULE-BEARING TABLE, judged BEFORE the view arms because it is not a view and they do not
            // apply to it.** A rule has no `security_invoker` option — there is no way to ask PostgreSQL to
            // rewrite it as the caller — so the only escape is that this connection owns the host relation
            // itself, in which case evaluating as the owner and as the caller are the same evaluation. That is
            // the view arm's own logic minus the option that does not exist here.
            //
            // Refused whoever owns it and not only for an exempt owner, deliberately: `checkAsUser` becomes the
            // host owner, and this connection cannot audit that role's exemption over time — a `BYPASSRLS` added
            // to it later would reopen the leak silently, with nothing in this database changing. twes-in emits
            // no rules, so the conservative direction costs nothing and the message names the supported
            // alternative.
            // **KEYED ON `has_user_rule`, NOT ON KIND — and round 16 filed a P0 because it was keyed on kind.**
            // `has_user_rule` was selected, declared in both array shapes, and read by NOTHING; the arm tested
            // `'r' === kind || 'p' === kind`, so a **VIEW** carrying a `DO INSTEAD` rule fell straight through to
            // the `security_invoker` short-circuit below and its rule was never judged. Reproduced: a
            // cross-tenant read AND a cross-tenant write through a `security_invoker = true` view with two rules,
            // while this method returned CLEAN.
            //
            // **The message below made it worse, which is the part worth remembering.** It said "use a view with
            // `security_invoker = true` instead" — and adding the `DO INSTEAD` rule that makes such a view
            // writable is the standard pre-9.3 updatable-view idiom, so the remediation steered an operator
            // directly into the one shape the arm could not see. A guard that names a workaround it does not
            // cover is worse than one that names none.
            //
            // `security_invoker` does NOT save a rule-bearing view: the option governs how the VIEW BODY is
            // evaluated, while a `DO INSTEAD` rule's own rewritten query is judged against the rule's host
            // relation owner regardless. So a rule is judged BEFORE the view arms, for every kind.
            //
            // This is the "a permission that nothing consults permits everything" shape § Gotchas records, and it
            // was committed inside the fix for the previous instance of it. The rule now is the one that entry
            // states: a column added to a query must be READ by that query's failure path in the same change.
            if (self::isTrue($object['has_user_rule'] ?? false)) {
                if (self::isTrue($object['owned_by_caller'] ?? false)) {
                    continue;
                }

                $violations[] = \sprintf(
                    '%s is a %s carrying a rewrite RULE and owned by %s. A rule rewrites into its HOST '
                    . 'relation\'s owner (`checkAsUser`), exactly as a view body does, so row-level security is '
                    . 'SKIPPED rather than evaluated as the caller when that owner is exempt%s. `security_invoker` '
                    . 'does not help: it governs the view BODY, not a DO INSTEAD rule\'s own rewritten query. '
                    . 'Drop the rule — an auto-updatable view owned by a role that is itself subject to the '
                    . 'policies is the supported shape',
                    $object['object'],
                    match ($object['kind']) {
                        'p' => 'partitioned table',
                        'v' => 'view',
                        'm' => 'materialised view',
                        'f' => 'foreign table',
                        default => 'table',
                    },
                    $object['owner'],
                    self::isTrue($object['owner_exempt'])
                        ? ', WHICH IT IS (superuser or BYPASSRLS) — a cross-tenant read AND write are reachable '
                            . 'through it right now'
                        : '. That owner is not exempt today, which is not a property this connection can rely '
                            . 'on: a BYPASSRLS granted to it later reopens the leak with nothing here changing',
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

            // A view owned by the QUERYING role is safe without security_invoker: evaluating policies as the
            // owner and evaluating them as the caller are the same evaluation. Checked after security_invoker
            // for the same precedence reason recorded above — ownership only ever narrows the question.
            if (self::isTrue($object['owned_by_caller'] ?? false)) {
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

        // A LIVE `LISTEN` REGISTRATION — the carrier round 13 found this method not asking about. `LISTEN` needs
        // **no privilege**, carries no row security, is scoped to no tenant, survives COMMIT, and delivers to
        // every listener in the database. A connection released to the pool while listening delivers the
        // PREVIOUS tenant's NOTIFY payloads to the next holder.
        //
        // Note the asymmetry that made it worth finding: `DISCARD ALL` DOES clear it (`UNLISTEN *`), so the
        // REMEDY already covered it and the DETECTION did not — and detection is the half wired into
        // acquisition. This method's own argument applies more strongly here than to a temp table, because
        // there is no capability to revoke: any role may LISTEN.
        $channels = $connection->query('SELECT pg_listening_channels() AS name ORDER BY 1');

        if (false === $channels) {
            throw new \RuntimeException('Could not inspect this session\'s LISTEN registrations.');
        }

        /** @var list<array{name: string, kind: string}> $relationRows */
        $relationRows = $relations->fetchAll(\PDO::FETCH_ASSOC);
        /** @var list<array{name: string}> $cursorRows */
        $cursorRows = $cursors->fetchAll(\PDO::FETCH_ASSOC);
        /** @var list<array{name: string}> $channelRows */
        $channelRows = $channels->fetchAll(\PDO::FETCH_ASSOC);

        $violations = self::sessionLifetimeDataViolations($relationRows, $cursorRows, $channelRows);

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
     * @param list<array{name: string}> $channels LISTEN registrations, which any role may create
     *
     * @return list<string>
     */
    public static function sessionLifetimeDataViolations(
        array $relations,
        array $cursors,
        // REQUIRED, not defaulted, and round 14 was right to flag it. `$relations` and `$cursors` are required
        // and this was optional, which made the LISTEN arm the one a forgetful caller silently skipped — and it
        // is the arm where that costs most, because this method's own docblock says a `LISTEN` registration needs
        // NO privilege and therefore has no capability to revoke: detection is the only control that exists for
        // it. A fail-open default on the only detectable-and-not-preventable carrier is the "permission that
        // nothing consults" shape § Gotchas records. There is exactly one caller in `src/`, so making it
        // required costs nothing but forces the next caller to decide rather than inherit a blank.
        array $channels,
    ): array {
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

        foreach ($channels as $channel) {
            $violations[] = \sprintf(
                'this session is LISTENing on %s, which survives COMMIT and is scoped to no tenant — the next '
                . 'holder of this connection receives the previous tenant\'s NOTIFY payloads',
                $channel['name'],
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
     * **An open transaction is ROLLED BACK, not refused** — see the inline comment for why the original
     * refusal had the direction backwards. `DISCARD ALL` cannot run inside a transaction block (SQLSTATE
     * 25001), so something has to give, and for a cleanup routine the safe thing to give is the transaction.
     */
    public static function discardSessionState(\PDO $connection): void
    {
        // ROLLS BACK AND CLEARS, rather than refusing. The first version threw inside a transaction, on the
        // reasoning that DISCARD ALL raises 25001 there and an explicit refusal beats an obscure failure.
        // Round 12 refuted the direction: a connection is returned to the pool most often on an EXCEPTION
        // path, where a transaction is still open — so the one state this method refused was the state it
        // would most often be called in, and the dirtiest connection went back to the pool with the temp
        // table, the held cursor and the binding still on it.
        //
        // For a CLEANUP routine, fail-closed means "clear it anyway". An open transaction at release is
        // already an error, and rolling it back is the only safe interpretation: committing would persist
        // whatever half-finished work raised the exception.
        if ($connection->inTransaction()) {
            try {
                $connection->rollBack();
            } catch (\PDOException $exception) {
                // A BROKEN connection reports `inTransaction() === true`, because pdo_pgsql implements it as
                // `PQtransactionStatus(...) > PQTRANS_IDLE` and a dead backend answers `PQTRANS_UNKNOWN`. So the
                // rollback raises — and round 13 found that PDOException REPLACING the in-flight business
                // exception in a `finally`-shaped release path, which is the masked-failure shape this
                // repository records four times. Introduced by the round-12 fix that got the direction right for
                // the healthy case and left the adjacent one throwing.
                //
                // NOT a swallow: a rollback that fails means the connection is unusable, so it must be EVICTED
                // rather than returned, and the caller has to be told which. Re-thrown as a dedicated type
                // carrying the original as its cause, so a pool can distinguish "clean me again" from "throw me
                // away" without string-matching a driver message.
                throw ConnectionMustBeEvicted::becauseCleanupFailed($exception);
            }
        }

        try {
            $connection->exec('DISCARD ALL');
        } catch (\PDOException $exception) {
            throw ConnectionMustBeEvicted::becauseCleanupFailed($exception);
        }
    }

    /**
     * Refuse a connection that can reach a LARGE OBJECT — the eighth carrier, and the longest-lived.
     *
     * The seventh class covers *session*-lifetime carriers. A large object is the same "copy rows out from
     * under a policy" channel with a **permanent** lifetime, and every property that makes it dangerous is a
     * property of PostgreSQL rather than of our schema:
     *
     *  - `pg_largeobject` is a system catalogue and **cannot carry row-level security at all**.
     *  - `lo_from_bytea()` and `lo_get()` need no privilege the restricted runtime role lacks.
     *  - A large object's default ACL is **owner-only** — and since every request connects as the *same*
     *    runtime role, owner-only means *every tenant's blob is readable under every binding*.
     *  - `DISCARD ALL` does not touch it, so {@see self::discardSessionState()} is not a remedy.
     *
     * [Verified on this server as the restricted role: `lo_from_bytea` created an object owned by `twes` with
     * a NULL ACL, and `lo_get` returned its bytes while the session was bound to a DIFFERENT tenant;
     * `relrowsecurity` on `pg_largeobject` is false.]
     *
     * A billing product generating invoice PDFs is the canonical large-object use, which is what makes this
     * worth a guard rather than a note. **The rule is zero: blobs belong in a policed tenant-owned table or
     * outside the database entirely.** That is a stricter rule than "police them", chosen because there is no
     * way to police them — so the only enforceable statement is that none exists.
     *
     * @throws \RuntimeException if any large object exists
     */
    public static function assertNoLargeObjectIsReachable(\PDO $connection): void
    {
        $statement = $connection->query(
            'SELECT m.oid, m.lomowner::regrole::text AS owner, '
            . 'coalesce(m.lomacl::text, \'(owner only)\') AS acl '
            . 'FROM pg_largeobject_metadata m ORDER BY m.oid',
        );

        if (false === $statement) {
            throw new \RuntimeException('Could not inspect large objects.');
        }

        /** @var list<array{oid: int|string, owner: string, acl: string}> $objects */
        $objects = $statement->fetchAll(\PDO::FETCH_ASSOC);

        if ([] === $objects) {
            return;
        }

        $described = array_map(
            static fn(array $object): string => \sprintf(
                'large object %s owned by %s, acl %s',
                (string) $object['oid'],
                $object['owner'],
                $object['acl'],
            ),
            $objects,
        );

        throw new \RuntimeException(
            'This database contains large objects, which cannot carry row-level security at any privilege '
            . 'level and are readable under whatever tenant is bound when they are read: '
            . implode('; ', $described)
            . '. twes-in stores blobs in a policed tenant-owned table or outside the database; there is no '
            . 'way to police pg_largeobject, so the only enforceable rule is that none exists. DISCARD ALL '
            . 'does not clear them.',
        );
    }

    /**
     * Refuse a connection that can CREATE a large object — the capability, not just the residue.
     *
     * {@see self::assertNoLargeObjectIsReachable()} detects one that already exists, and on its own that is the
     * shape of check somebody disables: it is composed into acquisition and throws on ANY row, so a single
     * request reaching `lo_from_bytea` — the canonical invoice-PDF path Wave 4 will write — permanently refuses
     * **every subsequent acquisition** until a privileged role unlinks it. A permanent object plus an
     * unconditional refusal plus the hot path is an outage, and this class makes exactly that argument against
     * asserting cross-database `CONNECT`.
     *
     * So the capability is removed, which is what the `TEMPORARY` sibling does and what the eighth carrier was
     * missing: it stated a rule and offered no way to enforce it. [Verified: the restricted runtime role could
     * call `lo_create`, `lo_from_bytea`, `lo_get`, `lo_put`, `lo_unlink` and `lowrite` — all six.]
     *
     * PostgreSQL leaves `proacl` NULL on FOUR of the five checked here — `lo_create`, `lo_from_bytea`, `lo_put`
     * and `lowrite` — and a NULL `proacl` means "EXECUTE to PUBLIC", so a fresh cluster grants them to the
     * runtime role. **`lo_import` is the exception and the docblock used to lump it in with the rest:** it ships
     * as `{postgres=X/postgres}`, so PUBLIC never held it. [Verified on PostgreSQL 18.4:
     * `has_function_privilege('twes', lo_import, 'EXECUTE')` is false on an untouched cluster while the other
     * four are true.] It stays in the DETECTOR's list regardless — a cluster where somebody granted it is
     * precisely what a checker is for — so the detector covering five while the remedy revokes four is correct
     * rather than a discrepancy, which is the sort of off-by-one that otherwise reads as an oversight.
     *
     * The remedy is `infra/README.md` § "No large objects, ever", which now actually contains it: round 14
     * found this docblock pointing at a `REVOKE` that appeared nowhere in that file, and the exception message
     * below sends an operator to the same place.
     *
     * Not composed into {@see self::assertConnectionCannotBypassPolicies()}, for the same reason the
     * `TEMPORARY` guard is not: the test database has not revoked these, so composing it would fail every run.
     * Addressed to production, recorded in `infra/README.md`, and owed as Wave 1 wiring.
     *
     * @throws \RuntimeException if any large-object WRITE function is callable
     */
    public static function assertConnectionCannotCreateLargeObjects(\PDO $connection): void
    {
        $callable = [];

        foreach (self::LARGE_OBJECT_WRITERS as $writer) {
            $statement = $connection->query(\sprintf(
                'SELECT bool_or(%s) AS callable FROM pg_proc p '
                . 'JOIN pg_namespace n ON n.oid = p.pronamespace '
                . "WHERE n.nspname = 'pg_catalog' AND p.proname = '%s'",
                self::privilegeIsReachableSql('p.proacl', 'EXECUTE', true),
                $writer,
            ));

            if (false === $statement) {
                throw new \RuntimeException('Could not inspect large-object function privileges.');
            }

            if (self::isTrue((string) $statement->fetchColumn())) {
                $callable[] = $writer . '()';
            }
        }

        if ([] === $callable) {
            return;
        }

        throw new \RuntimeException(\sprintf(
            'This connection can create large objects (%s). pg_largeobject cannot carry row-level security at '
            . 'any privilege level, its default ACL is owner-only — and since every request connects as the '
            . 'SAME role, owner-only means every tenant\'s blob is readable under every binding — and DISCARD '
            . 'ALL cannot clear it. REVOKE EXECUTE on these FROM PUBLIC; see infra/README.md. Blobs belong in '
            . 'a policed tenant-owned table or outside the database.',
            implode(', ', $callable),
        ));
    }

    /**
     * Refuse a connection that can create TEMPORARY objects — because `pg_temp` SHADOWS `public`.
     *
     * The seventh class detects a temporary relation that already exists. This removes the capability, and the
     * two are not redundant: detection runs at acquisition, and a temp table created *after* that is invisible
     * until the next acquisition.
     *
     * **Why shadowing makes this worse than an extra unpoliced table.** `pg_temp` precedes `public` in the
     * effective search path, so a temporary table named after a real one intercepts every UNQUALIFIED
     * reference to it:
     *
     * ```
     * -- bound to tenant A
     * CREATE TEMPORARY TABLE invoices AS SELECT * FROM public.invoices;
     * -- connection returned to the pool, next holder bound to tenant B, ordinary application SQL:
     * SELECT * FROM invoices;   -- resolves to pg_temp.invoices: tenant A's rows, unpoliced
     * ```
     *
     * The leak arrives under the real table's own name, through queries no reviewer would look at twice.
     * [Verified on this server: with a temporary `shadow_probe` present, `current_schemas(true)` reads
     * `{pg_temp_6,pg_catalog,public}` and an unqualified `shadow_probe::regclass` resolves into `pg_temp_6`
     * rather than `public`. The resolution is what was verified; the row read through it was not, so this
     * docblock claims the former only.]
     *
     * **Not part of {@see self::assertConnectionCannotBypassPolicies()}, and that is deliberate rather than an
     * omission.** This project's own test database GRANTS `TEMPORARY` to the runtime role, because the
     * column-fidelity suite needs a scratch table that leaves nothing behind — so composing this into the
     * acquisition check would fail every test run. The requirement is therefore addressed to **production**,
     * is recorded in `infra/README.md` beside the sibling requirements (the runtime role must not own the
     * policed tables, and must not hold `TRUNCATE`), and is owed as Wave 1 wiring. Disclosed here because
     * "a control asserted in prose and enforced nowhere is not a control" is this repository's most-repeated
     * lesson, and a capability with a tested assertion and a named owner is not the same thing as prose.
     *
     * @throws \RuntimeException if TEMPORARY on the current database is reachable
     */
    public static function assertConnectionCannotCreateTemporaryObjects(\PDO $connection): void
    {
        // By REACHABILITY, like every other privilege question in this class: aclexplode over datacl, with
        // membership tested against the grantee. A NULL datacl means PostgreSQL's default, which grants
        // TEMPORARY (and CONNECT) to PUBLIC — so a NULL ACL is the DANGEROUS case here, exactly as it is for
        // a function's EXECUTE, and reading it as "no grants" would certify every default database as safe.
        $statement = $connection->query(
            'SELECT current_database() AS db, ('
            . '  SELECT ' . self::privilegeIsReachableSql('d.datacl', 'TEMPORARY', true) . ' '
            . '  FROM pg_database d WHERE d.datname = current_database()'
            . ') AS can_create_temp',
        );

        if (false === $statement) {
            throw new \RuntimeException('Could not inspect the database ACL for TEMPORARY.');
        }

        /** @var array{db: string, can_create_temp: bool|string}|false $row */
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if (false === $row) {
            throw new \RuntimeException('Could not read the current database from pg_database.');
        }

        if (self::isTrue($row['can_create_temp'])) {
            throw new \RuntimeException(\sprintf(
                'This connection can create TEMPORARY objects in %s. A temporary table carries no row-level '
                . 'security and pg_temp PRECEDES public in the search path, so a temporary table named after '
                . 'a policed one intercepts every unqualified reference to it — the next holder of this '
                . 'connection reads the previous tenant\'s rows under the real table\'s own name. '
                . 'REVOKE TEMPORARY ON DATABASE %s FROM PUBLIC, and do not grant it to the runtime role. '
                . 'Note a NULL datacl means PostgreSQL\'s default, which grants TEMPORARY to PUBLIC.',
                $row['db'],
                $row['db'],
            ));
        }
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

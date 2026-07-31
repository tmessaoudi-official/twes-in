<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Integration\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Infrastructure\Tenancy\Exception\NoCurrentTenant;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\Tests\Integration\DatabaseRequirement;

/**
 * Runs against a real PostgreSQL server, because the guarantee under test is the *database's*, not the
 * application's. A mock would only prove that this test agrees with itself.
 *
 * A cross-tenant read is a reportable data breach, so the standard here is higher than "the happy path
 * works": the suite also proves the guard is load-bearing by removing it and watching data leak.
 *
 * **Two connections, and the split is the point.** An earlier version of this suite ran everything on one
 * role that also owned the probe table — and admitted it in a comment ("the application connects as the
 * owner") while infra/README.md required the opposite. A review proved what that costs: the connection
 * the suite certified as unable to bypass row-level security could disable the policy or `TRUNCATE` the
 * table in one statement, so every assertion here was made against a role that could step around the
 * thing being asserted. So `$connection` is now the restricted **runtime** role and `$owner` is the
 * separate **owning** role that migrations use; the runtime role holds DML and nothing more. The roles are
 * provisioned by scripts/dev/provision-test-database.sh, which explains what each one makes testable.
 */
#[CoversClass(PostgresRowLevelSecurityIsolation::class)]
final class TenantIsolationTest extends TestCase
{
    private const string TABLE = 'tenant_isolation_probe';

    /**
     * A SECOND policed table, whose only job is to make the inspected COUNT meaningful.
     *
     * With one table, `assertPolicedTablesAreBeyondThisRolesReach()`'s `return count($tables)` was
     * hardcodable to `1` and the assertion checking it passed against the mutant — so the assertion did not
     * do what its own comment claimed. Round 5 proved that. A second table means only a real count satisfies
     * both, and it also proves the query iterates rather than returning its first row.
     */
    private const string SECOND_TABLE = 'tenant_isolation_probe_two';

    private const string TENANT_A = '01926b3c-0000-7000-8000-00000000000a';
    private const string TENANT_B = '01926b3c-0000-7000-8000-00000000000b';

    /** The restricted runtime role: DML only, owns nothing, holds no TRUNCATE. */
    private \PDO $connection;

    /** The owning role: creates the table and its policy, exactly as a migration would. */
    private \PDO $owner;

    protected function setUp(): void
    {
        $this->connection = self::connect();
        $this->owner = self::connectAsOwner();
        $this->createProbeTable();
    }

    protected function tearDown(): void
    {
        // Guarded because PHPUnit runs tearDown even when setUp called markTestSkipped, and an
        // unguarded dereference turns nine intended skips into nine type errors that name the wrong
        // file — hiding the one message an operator needs ("no PostgreSQL server reachable").
        if (isset($this->owner)) {
            $this->owner->exec('DROP TABLE IF EXISTS ' . self::TABLE);
            $this->owner->exec('DROP TABLE IF EXISTS ' . self::SECOND_TABLE);
        }
    }

    // ------------------------------------------------------------------ the guarantee

    public function testAQueryReturnsOnlyTheBoundTenantsRows(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        $this->connection->beginTransaction();
        $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_A),
        ));

        // Note there is no WHERE clause. The scoping is the server's, not the query's — which is the
        // entire reason this is row-level security and not a filter the caller has to remember.
        $labels = $this->fetchAllLabels();

        $this->connection->rollBack();

        self::assertSame(['a-one', 'a-two'], $labels);
    }

    public function testTheOtherTenantSeesItsOwnRowsAndOnlyThose(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        $this->connection->beginTransaction();
        $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_B),
        ));

        $labels = $this->fetchAllLabels();

        $this->connection->rollBack();

        self::assertSame(['b-one'], $labels);
    }

    /**
     * The property everything else rests on: an unscoped session sees nothing, not everything.
     *
     * `current_setting('twes.tenant_id', true)` is NULL when unset, so the policy's comparison is NULL,
     * so no row qualifies. Getting this backwards — an unset variable matching every row — is how a
     * multi-tenant system leaks its entire database on one forgotten binding.
     */
    public function testAnUnboundSessionSeesNothingRatherThanEverything(): void
    {
        $this->connection->beginTransaction();

        $labels = $this->fetchAllLabels();

        $this->connection->rollBack();

        self::assertSame([], $labels);
    }

    /**
     * The non-vacuity proof, and the acceptance criterion for Wave 0.
     *
     * Remove the guard and the same query — byte for byte — returns every tenant's rows. So the
     * assertions above are testing the policy, and this test is the one that fails the day somebody
     * drops it from a migration.
     */
    public function testRemovingTheIsolationLeaksEveryTenantsRows(): void
    {
        // Disabled by the OWNER, because the runtime role cannot — which is itself asserted, below.
        $this->owner->exec('ALTER TABLE ' . self::TABLE . ' DISABLE ROW LEVEL SECURITY');

        try {
            $this->connection->beginTransaction();
            $leaked = $this->fetchAllLabels();
            $this->connection->rollBack();
        } finally {
            $this->owner->exec('ALTER TABLE ' . self::TABLE . ' ENABLE ROW LEVEL SECURITY');
        }

        self::assertSame(
            ['a-one', 'a-two', 'b-one'],
            $leaked,
            'Disabling RLS must leak every tenant. If it does not, this suite is not testing isolation '
            . 'at all and the assertions above prove nothing.',
        );
    }

    // ------------------------------------------------------------------ writes

    public function testATenantCannotUpdateAnotherTenantsRow(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        $this->connection->beginTransaction();
        $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_A),
        ));

        $affected = $this->connection->exec(
            "UPDATE " . self::TABLE . " SET label = 'stolen' WHERE label = 'b-one'",
        );

        $this->connection->rollBack();

        self::assertSame(0, $affected, "Tenant A must not be able to reach tenant B's row.");
    }

    public function testATenantCannotInsertARowBelongingToAnotherTenant(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        $this->connection->beginTransaction();
        $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_A),
        ));

        try {
            $this->expectException(\PDOException::class);

            $statement = $this->connection->prepare(
                'INSERT INTO ' . self::TABLE . ' (id, company_id, label) VALUES (?, ?, ?)',
            );
            $statement->execute([99, self::TENANT_B, 'planted']);
        } finally {
            $this->connection->rollBack();
        }
    }


    /**
     * The case an earlier version of this suite could not see, and the reason the policy uses `nullif`.
     *
     * Every other test here gets a fresh connection from setUp, so "unbound" means "never bound". In
     * production a connection is reused, and after one `set_config` the GUC's reset value is the empty
     * string rather than NULL — so a naive policy raises SQLSTATE 22P02 on the next unbound query
     * instead of returning zero rows. This binds, commits, and then queries unbound on the SAME
     * connection: it must still see nothing, and must not error.
     */
    public function testAReusedConnectionStillFailsClosedAfterAPreviousBinding(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        $this->connection->beginTransaction();
        $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_A),
        ));
        $this->connection->commit();

        // Same connection, no binding — exactly what the next request off a pool would do.
        $this->connection->beginTransaction();
        $setting = $this->connection->query(
            "SELECT current_setting('" . PostgresRowLevelSecurityIsolation::TENANT_SETTING . "', true)",
        );
        $reset = false === $setting ? null : $setting->fetchColumn();
        $labels = $this->fetchAllLabels();
        $this->connection->rollBack();

        self::assertSame(
            '',
            $reset,
            'If this is NULL, PostgreSQL changed its custom-GUC reset behaviour and the nullif in the '
            . 'policy is no longer load-bearing — check before removing it.',
        );
        self::assertSame([], $labels, 'A reused, unbound connection must see nothing — and must not error.');
    }

    public function testBindingIsVerifiedRatherThanAssumed(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        $this->connection->beginTransaction();
        $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_A),
        ));

        $statement = $this->connection->query(
            "SELECT current_setting('" . PostgresRowLevelSecurityIsolation::TENANT_SETTING . "', true)",
        );
        $actual = false === $statement ? null : $statement->fetchColumn();

        $this->connection->rollBack();

        self::assertSame(self::TENANT_A, $actual);
    }

    public function testATenantCannotDeleteAnotherTenantsRow(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        $this->connection->beginTransaction();
        $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_A),
        ));

        $affected = $this->connection->exec("DELETE FROM " . self::TABLE . " WHERE label = 'b-one'");

        $this->connection->rollBack();

        self::assertSame(0, $affected);
    }

    /**
     * The WITH CHECK half of the policy: a tenant must not be able to hand its own row to another
     * tenant, which would be data exfiltration by UPDATE rather than by SELECT.
     */
    public function testATenantCannotReassignItsOwnRowToAnotherTenant(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        $this->connection->beginTransaction();
        $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_A),
        ));

        try {
            $this->expectException(\PDOException::class);
            $this->connection->exec(
                'UPDATE ' . self::TABLE . " SET company_id = '" . self::TENANT_B . "' WHERE label = 'a-one'",
            );
        } finally {
            $this->connection->rollBack();
        }
    }

    // ------------------------------------------------------------------ fail-closed behaviour

    public function testBindingWithoutATenantIsRefused(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        $this->connection->beginTransaction();

        try {
            $this->expectException(NoCurrentTenant::class);
            $isolation->bind($this->connection, InMemoryTenantContext::empty());
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * `SET LOCAL` outside a transaction is discarded by PostgreSQL, which would leave the session
     * unscoped while `bind()` appeared to have worked. That is the worst possible outcome, so it is an
     * exception rather than a warning.
     */
    public function testBindingOutsideATransactionIsRefused(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/inside a transaction/');

        $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_A),
        ));
    }

    public function testTheApplicationRoleCannotBypassRowLevelSecurity(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        // Returns the number of policed tables it inspected rather than nothing. The earlier version of
        // this test called the method and then declared expectNotToPerformAssertions(), which meant a
        // check that had silently stopped looking at anything was indistinguishable from a safe role —
        // and a review proved it: replacing the whole privilege query with `SELECT false, false` left this
        // test green. Asserting the count makes the SQL load-bearing.
        // TWO policed tables, deliberately. With one, `return \count($tables)` was hardcodable to `1` and
        // this assertion passed against it — so the comment claiming "asserting the count makes the SQL
        // load-bearing" was not true. Round 5 proved that with a surviving mutant.
        self::assertSame(
            2,
            $isolation->assertConnectionCannotBypassPolicies($this->connection),
            'The check must report BOTH policed tables it inspected. A hardcoded 1 — or a zero, which would '
            . 'mean it certified a connection against no policy at all — must fail here.',
        );
    }

    /**
     * The refusal branch, live, against a real `BYPASSRLS` connection.
     *
     * Previously this branch existed only as a pure predicate over hand-written array rows, so the *query*
     * feeding it was asserted by nothing — a review replaced it with `SELECT false AS rolsuper, false AS
     * rolbypassrls` and the whole suite stayed green. `twes_bypass` exists solely to close that.
     */
    public function testAConnectionWhoseRoleHasBypassRlsIsRefused(): void
    {
        $privileged = self::connectAs('TWES_TEST_DB_BYPASS_USER', 'TWES_TEST_DB_BYPASS_PASSWORD');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/has BYPASSRLS/');

        new PostgresRowLevelSecurityIsolation()->assertConnectionCannotBypassPolicies($privileged);
    }

    /**
     * Reachability: the privilege is not held, it is one `SET ROLE` away.
     *
     * `twes_member` reads `rolsuper = f, rolbypassrls = f` in its own `pg_roles` row, so the naive
     * `rolname = current_user` check this class started with accepted it — and then `SET ROLE twes_bypass`
     * reached every tenant. This is the round-3 fix, which until now had no test at all: reverting the
     * predicate to a single-row lookup left the suite green.
     */
    public function testAConnectionThatCanReachBypassRlsBySetRoleIsRefused(): void
    {
        $member = self::connectAs('TWES_TEST_DB_MEMBER_USER', 'TWES_TEST_DB_MEMBER_PASSWORD');

        // The precondition, asserted rather than assumed: the role's own attributes are harmless. Without
        // this, a mis-provisioned twes_member with BYPASSRLS of its own would make the test pass for the
        // wrong reason and prove nothing about reachability.
        $own = $member->query(
            'SELECT rolsuper OR rolbypassrls AS privileged FROM pg_roles WHERE rolname = current_user',
        );
        self::assertNotFalse($own);
        self::assertNotContains(
            $own->fetchColumn(),
            [true, 't', '1'],
            'twes_member must hold no privilege of its own, or this test proves nothing about SET ROLE.',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/one SET ROLE/');

        new PostgresRowLevelSecurityIsolation()->assertConnectionCannotBypassPolicies($member);
    }

    /**
     * The same reachability, but from `session_user` while `current_user` looks harmless.
     *
     * PostgreSQL authorises `SET ROLE` against the **session** user, so a connection that arrives with
     * `current_user` already changed — `options='-c role=…'` in the DSN needs no application code and is
     * the same shape as the tenant-pinning bypass this class already defends against — can reach every
     * role the session user is a member of, while a predicate over `current_user` enumerates a strictly
     * smaller set. Here `current_user` is `twes` (a member of nothing) and `session_user` is `twes_member`
     * (a member of `twes_bypass`), which is exactly the state that fooled the previous predicate.
     */
    public function testAConnectionWhoseSessionUserCanReachBypassRlsIsRefused(): void
    {
        $runtimeRole = getenv('TWES_TEST_DB_USER');

        if (!\is_string($runtimeRole)) {
            self::markTestSkipped('TWES_TEST_DB_USER must be set.');
        }

        $switched = self::connectAs(
            'TWES_TEST_DB_MEMBER_USER',
            'TWES_TEST_DB_MEMBER_PASSWORD',
            "options='-c role=" . $runtimeRole . "'",
        );

        $whoami = $switched->query('SELECT current_user, session_user');
        self::assertNotFalse($whoami);
        /** @var array{current_user: string, session_user: string} $identities */
        $identities = $whoami->fetch(\PDO::FETCH_ASSOC);

        // The precondition the finding turns on: the two differ. If the DSN option were ignored they would
        // be equal, the current_user predicate would already catch it, and the test would pass vacuously.
        self::assertNotSame(
            $identities['session_user'],
            $identities['current_user'],
            'The DSN role option did not take effect, so this test would pass without exercising the '
            . 'session_user path at all.',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/one SET ROLE/');

        new PostgresRowLevelSecurityIsolation()->assertConnectionCannotBypassPolicies($switched);
    }

    /**
     * Bypass #0, live: owning the policed table is enough, with no privileged attribute anywhere.
     *
     * The owner is neither a superuser nor `BYPASSRLS` — both checks accept it — and it can still reach
     * every tenant in two statements. `FORCE ROW LEVEL SECURITY` does not help: it stops an owner
     * *skipping* policies, not *removing* them. This is the P0 a review found, so the proof runs in both
     * directions: the check refuses the owner, and the escalation it warns about genuinely works.
     */
    public function testTheRoleThatOwnsAPolicedTableIsRefused(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        $attributes = $this->owner->query(
            'SELECT rolsuper OR rolbypassrls AS privileged FROM pg_roles WHERE rolname = current_user',
        );
        self::assertNotFalse($attributes);
        self::assertNotContains(
            $attributes->fetchColumn(),
            [true, 't', '1'],
            'The owning role must hold no privileged attribute, or this test would prove nothing beyond '
            . 'the attribute check that already existed.',
        );

        try {
            $isolation->assertConnectionCannotBypassPolicies($this->owner);
            self::fail('A connection owning a policed table must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertMatchesRegularExpression('/reach around/', $exception->getMessage());
            self::assertStringContainsString(self::TABLE, $exception->getMessage());
        }

        // And the escalation is real, not theoretical: one statement from the owner and the policy is gone.
        $this->owner->exec('ALTER TABLE ' . self::TABLE . ' DISABLE ROW LEVEL SECURITY');

        try {
            $statement = $this->owner->query('SELECT count(*) FROM ' . self::TABLE);
            self::assertNotFalse($statement);
            self::assertSame(3, (int) $statement->fetchColumn(), 'Every tenant is now visible.');
        } finally {
            $this->owner->exec('ALTER TABLE ' . self::TABLE . ' ENABLE ROW LEVEL SECURITY');
        }
    }

    /**
     * OWNERSHIP reached by MEMBERSHIP, not held by inheritance — the axis that survived every mutant.
     *
     * `testTheRoleThatOwnsAPolicedTableIsRefused` connects **as** the owner, and a role always satisfies
     * `pg_has_role()` on itself under every mode, so swapping `MEMBER` for `USAGE` there is an equivalent
     * mutant. Round 5 flagged it, and the reason it matters is not academic: the *same* distinction was got
     * wrong two lines below on the TRUNCATE axis, and that one was a working exploit. An untested semantic is
     * how a correct line and an incorrect line end up side by side.
     *
     * The shape needed is a role that can REACH an owner it does not INHERIT. `twes_probe_owner` is granted
     * to the owning role `WITH ADMIN OPTION` by the provisioning script precisely so this test can hand it to
     * the runtime role `WITH INHERIT FALSE` and own a table with it.
     */
    public function testOwnershipReachableOnlyBySetRoleIsRefused(): void
    {
        $probeOwner = getenv('TWES_TEST_DB_PROBE_OWNER_ROLE');
        $runtimeRole = getenv('TWES_TEST_DB_USER');

        if (!\is_string($probeOwner) || !\is_string($runtimeRole)) {
            self::markTestSkipped('TWES_TEST_DB_PROBE_OWNER_ROLE and TWES_TEST_DB_USER must be set.');
        }

        $owned = self::TABLE . '_owned_elsewhere';

        $this->owner->exec('GRANT ' . $probeOwner . ' TO ' . $runtimeRole . ' WITH INHERIT FALSE');
        $this->owner->exec('DROP TABLE IF EXISTS ' . $owned);
        $this->owner->exec(
            'CREATE TABLE ' . $owned . ' (company_id uuid NOT NULL, id integer NOT NULL, '
            . 'PRIMARY KEY (company_id, id))',
        );

        try {
            // Policy FIRST, ownership second. Transferring ownership away means this connection can no longer
            // ALTER the table at all, so the policy would fail with "must be owner of table".
            foreach (PostgresRowLevelSecurityIsolation::policySqlFor($owned) as $statement) {
                $this->owner->exec($statement);
            }

            $this->owner->exec('ALTER TABLE ' . $owned . ' OWNER TO ' . $probeOwner);

            // The preconditions that make this a MEMBERSHIP test rather than an inheritance one. Without
            // them the test could pass for the wrong reason and prove nothing about the distinction.
            $semantics = $this->connection->query(
                "SELECT pg_has_role(session_user, '" . $probeOwner . "', 'MEMBER') AS by_membership, "
                . "pg_has_role(session_user, '" . $probeOwner . "', 'USAGE') AS by_inheritance",
            );
            self::assertNotFalse($semantics);
            /** @var array{by_membership: bool|string, by_inheritance: bool|string} $row */
            $row = $semantics->fetch(\PDO::FETCH_ASSOC);
            self::assertContains($row['by_membership'], [true, 't', '1'], 'reachable by SET ROLE');
            self::assertNotContains(
                $row['by_inheritance'],
                [true, 't', '1'],
                'NOT inherited — if this becomes true the grant stopped being WITH INHERIT FALSE and the '
                . 'membership-versus-inheritance distinction is no longer under test.',
            );

            try {
                PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection);
                self::fail('A table owned by a role reachable via SET ROLE must be refused.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString($owned, $exception->getMessage());
                self::assertStringContainsString('owned by ' . $probeOwner, $exception->getMessage());
            }
        } finally {
            // Order matters. The table may be owned by the probe role by now, and only a member of that role
            // can drop it — so SET ROLE first, drop, reset, and only then remove the grant. Revoking first
            // would strand a table nobody left in this test is allowed to drop.
            try {
                $this->owner->exec('SET ROLE ' . $probeOwner);
                $this->owner->exec('DROP TABLE IF EXISTS ' . $owned);
            } finally {
                $this->owner->exec('RESET ROLE');
                $this->owner->exec('DROP TABLE IF EXISTS ' . $owned);
                $this->owner->exec('REVOKE ' . $probeOwner . ' FROM ' . $runtimeRole);
            }
        }
    }

    /**
     * Membership in a PREDEFINED role is refused — the `REPLICATION` finding's twin.
     *
     * PostgreSQL's `pg_*` roles are ordinary `pg_roles` rows with `rolsuper`, `rolbypassrls` and
     * `rolreplication` all false, so an attribute check cannot see them. Round 6 proved two reach superuser:
     * `pg_execute_server_program` runs `COPY (…) TO PROGRAM` as the postgres OS user and thence a superuser
     * connection over the local socket, and `pg_write_server_files` writes arbitrary files as that user. All
     * fourteen predefined roles were certified CLEAN, with correctly-policed SQL throughout.
     *
     * Granted and revoked inside the test via the superuser-provisioned owner connection, so the runtime role
     * is left exactly as provisioned.
     */
    #[DataProvider('predefinedRolesThatMustBeRefused')]
    public function testMembershipInAPredefinedRoleIsRefused(string $predefinedRole): void
    {
        $runtimeRole = getenv('TWES_TEST_DB_USER');

        if (!\is_string($runtimeRole)) {
            self::markTestSkipped('TWES_TEST_DB_USER must be set.');
        }

        // GRANTed by a superuser, which the owner is not — so this needs its own connection. Skipped rather
        // than failed when unavailable: the pure-predicate coverage below still applies.
        $granter = self::superuserConnection();

        if (null === $granter) {
            self::markTestSkipped('No superuser connection available to grant a predefined role.');
        }

        $granter->exec('GRANT ' . $predefinedRole . ' TO ' . $runtimeRole);

        try {
            new PostgresRowLevelSecurityIsolation()->assertConnectionCannotBypassPolicies($this->connection);
            self::fail($predefinedRole . ' must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString($predefinedRole, $exception->getMessage());
            self::assertStringContainsString('predefined role', $exception->getMessage());
        } finally {
            $granter->exec('REVOKE ' . $predefinedRole . ' FROM ' . $runtimeRole);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function predefinedRolesThatMustBeRefused(): iterable
    {
        // The two with a PROVEN path to superuser, plus three that are merely broad — because the rule is
        // "no pg_* membership at all" rather than an enumerated blocklist, so a future PostgreSQL adding one
        // is covered on the day it exists.
        yield 'pg_execute_server_program' => ['pg_execute_server_program'];
        yield 'pg_write_server_files' => ['pg_write_server_files'];
        yield 'pg_read_all_data' => ['pg_read_all_data'];
        yield 'pg_write_all_data' => ['pg_write_all_data'];
        yield 'pg_monitor' => ['pg_monitor'];
    }

    /**
     * `pg_database_owner` is NOT refused, and that exclusion is deliberate rather than an oversight.
     *
     * Membership in it is granted implicitly to whoever owns the current database, and it confers no
     * capability that reaches around row security. Refusing it would report every owner connection under the
     * wrong heading and bury the real finding — that the connection owns tables.
     */
    public function testPgDatabaseOwnerIsNotTreatedAsAPredefinedRoleViolation(): void
    {
        $attributes = $this->owner->query(
            "SELECT pg_has_role(current_user, 'pg_database_owner', 'MEMBER') AS reaches",
        );
        self::assertNotFalse($attributes);
        self::assertContains(
            $attributes->fetchColumn(),
            [true, 't', '1'],
            'The owner must reach pg_database_owner, or this test proves nothing about the exclusion.',
        );

        try {
            new PostgresRowLevelSecurityIsolation()->assertConnectionCannotBypassPolicies($this->owner);
            self::fail('The owner is still refused, for owning tables.');
        } catch (\RuntimeException $exception) {
            // Refused for the RIGHT reason: ownership, not predefined-role membership.
            self::assertStringContainsString('reach around', $exception->getMessage());
            self::assertStringNotContainsString('predefined role', $exception->getMessage());
        }
    }

    /**
     * The runtime role must be unable to do what the owner just did.
     *
     * The other half of the P0: the check refusing the owner is only useful if the role it *accepts* truly
     * cannot reach the same escalation. Both attempts must be refused by the server, not by convention.
     */
    public function testTheRuntimeRoleCannotDisableOrTruncateThePolicedTable(): void
    {
        foreach ([
            'DISABLE ROW LEVEL SECURITY' => 'ALTER TABLE ' . self::TABLE . ' DISABLE ROW LEVEL SECURITY',
            'TRUNCATE' => 'TRUNCATE ' . self::TABLE,
        ] as $what => $sql) {
            try {
                $this->connection->exec($sql);
                self::fail('The runtime role must not be able to ' . $what . '.');
            } catch (\PDOException $exception) {
                // 42501 insufficient_privilege. Asserted on the SQLSTATE rather than the message, which is
                // localised, and rather than on "it threw" — a syntax error would also throw.
                self::assertSame(
                    '42501',
                    $exception->getCode(),
                    $what . ' must fail with insufficient_privilege, not: ' . $exception->getMessage(),
                );
            }
        }
    }

    /**
     * A policed table `ENABLE`d without `FORCE` leaves its owner exempt, so the check refuses it.
     *
     * This is the one violation reachable without any role misconfiguration at all — a migration that ran
     * `ENABLE ROW LEVEL SECURITY` and forgot the second statement. `policySqlFor()` emits both, which is
     * why it exists; this proves the check would catch a migration that did not use it.
     */
    public function testAPolicedTableThatIsNotForcedIsRefused(): void
    {
        $this->owner->exec('ALTER TABLE ' . self::TABLE . ' NO FORCE ROW LEVEL SECURITY');

        try {
            PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection);
            self::fail('A policed table that is not FORCEd must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertMatchesRegularExpression('/ENABLEd but not FORCEd/', $exception->getMessage());
        } finally {
            $this->owner->exec('ALTER TABLE ' . self::TABLE . ' FORCE ROW LEVEL SECURITY');
        }
    }

    /**
     * A database where nothing is policed is refused, not reported clean.
     *
     * The same vacuity that made a gate print OK after inspecting zero files: with no policed table this
     * connection is subject to no policy, which is the state the check exists to rule out rather than a
     * clean bill of health.
     */
    public function testTheCheckRefusesToCertifyADatabaseWithNoPolicedTable(): void
    {
        // EVERY policed table, or the vacuity guard is never reached and this case is itself vacuous — the
        // same trap the licence gate's "inspected nothing" case fell into when a second lock file appeared.
        $this->owner->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->owner->exec('DROP TABLE IF EXISTS ' . self::SECOND_TABLE);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no isolation to be subject to/');

        PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection);
    }

    /**
     * `bind()`'s pre-write check, which had no test: deleting it left the suite green.
     *
     * A tenant pinned in the DSN survives every COMMIT, so without this check the *first* bind succeeds on
     * a connection whose unbound statements silently read someone else's tenant.
     */
    public function testBindingOnAConnectionCarryingAPinnedTenantIsRefused(): void
    {
        $pinned = self::connect("options='-c " . PostgresRowLevelSecurityIsolation::TENANT_SETTING . '=' . self::TENANT_B . "'");
        $pinned->beginTransaction();

        try {
            new PostgresRowLevelSecurityIsolation()->bind($pinned, InMemoryTenantContext::forTenant(
                TenantId::fromString(self::TENANT_A),
            ));
            self::fail('bind() must refuse a connection that already carries a tenant id.');
        } catch (\RuntimeException $exception) {
            self::assertMatchesRegularExpression('/Refusing to bind/', $exception->getMessage());
        } finally {
            $pinned->rollBack();
        }
    }

    /**
     * Rebinding inside one transaction is refused, and the message says so rather than blaming a DSN.
     *
     * From inside a transaction a transaction-local write and a session-scope one read identically, so the
     * pre-write check cannot tell them apart and a second `bind()` trips it. Refusing is right — statements
     * already executed under the first tenant would share an atomic unit with statements under the second —
     * but the message previously asserted the value "can only have come from somewhere else", which is
     * false in exactly this case and sent the reader hunting a DSN option that was not there.
     */
    public function testRebindingWithinOneTransactionIsRefusedWithAnAccurateMessage(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        $this->connection->beginTransaction();
        $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_A),
        ));

        try {
            $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
                TenantId::fromString(self::TENANT_B),
            ));
            self::fail('A second bind() inside one transaction must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertMatchesRegularExpression(
                '/already bound a tenant/',
                $exception->getMessage(),
                'The message must name rebinding as a cause. Blaming only a DSN option sends the reader '
                . 'looking for something that is not there.',
            );
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * The fourth bypass: a tenant id pinned on the connection itself.
     *
     * `options='-c twes.tenant_id=…'` in a DSN needs no privilege and is exactly what a `DATABASE_URL`
     * carries. Because `bind()` writes transaction-locally, PostgreSQL restores that session value on
     * COMMIT, so the unbound path becomes scoped to whoever pinned it rather than to nothing — and
     * `bind()`'s read-back cannot see it. Checked at acquisition instead.
     */
    public function testAConnectionCarryingAPinnedTenantIsRefused(): void
    {
        $pinned = self::connect("options='-c " . PostgresRowLevelSecurityIsolation::TENANT_SETTING . '=' . self::TENANT_B . "'");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already carries/');

        new PostgresRowLevelSecurityIsolation()->assertConnectionCannotBypassPolicies($pinned);
    }

    public function testAVirginConnectionAndAPreviouslyBoundOneAreBothAccepted(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        // Never bound: current_setting is NULL.
        $isolation->assertNoTenantPinnedOnTheConnection($this->connection);

        // Bound once and committed: the reset value is the empty string, NOT NULL. Both mean "no tenant",
        // and treating '' as pinned would reject every recycled connection in production.
        $this->connection->beginTransaction();
        $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_A),
        ));
        $this->connection->commit();

        $isolation->assertNoTenantPinnedOnTheConnection($this->connection);

        // And it refuses to answer inside a transaction, where '' may shadow a live session pin.
        $this->connection->beginTransaction();

        try {
            $threw = false;
            $isolation->assertNoTenantPinnedOnTheConnection($this->connection);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            $this->connection->rollBack();
        }

        self::assertTrue($threw, 'The check must refuse to answer inside a transaction.');
    }

    /**
     * The throwing branch of the bypass check, which had no coverage at all: the only test called it on a
     * role that passes and then declared `expectNotToPerformAssertions()`, so a broken predicate was
     * indistinguishable from a safe role. Creating a BYPASSRLS role needs the privilege the application
     * role must not have, so the predicate is exercised directly — which is why it was extracted.
     */
    public function testTheBypassPredicateDetectsAPrivilegedRole(): void
    {
        self::assertTrue(PostgresRowLevelSecurityIsolation::roleCanBypassPolicies(
            ['rolsuper' => true, 'rolbypassrls' => false],
        ));
        self::assertTrue(PostgresRowLevelSecurityIsolation::roleCanBypassPolicies(
            ['rolsuper' => false, 'rolbypassrls' => true],
        ));
        self::assertFalse(PostgresRowLevelSecurityIsolation::roleCanBypassPolicies(
            ['rolsuper' => false, 'rolbypassrls' => false],
        ));

        // pdo_pgsql reports booleans as PHP bools or as "t"/"f" depending on the build, so both spellings
        // must be understood — a string 'f' read as truthy would invert the whole check.
        self::assertTrue(PostgresRowLevelSecurityIsolation::roleCanBypassPolicies(
            ['rolsuper' => 't', 'rolbypassrls' => 'f'],
        ));
        self::assertFalse(PostgresRowLevelSecurityIsolation::roleCanBypassPolicies(
            ['rolsuper' => 'f', 'rolbypassrls' => 'f'],
        ));

        // REPLICATION, the third attribute — and the one that was missing. It does not defeat the policy; it
        // goes around the query layer the policy lives in, because pg_basebackup copies heap files and row
        // security never applies to a physical read.
        self::assertTrue(PostgresRowLevelSecurityIsolation::roleCanBypassPolicies(
            ['rolsuper' => false, 'rolbypassrls' => false, 'rolreplication' => true],
        ));
        self::assertTrue(PostgresRowLevelSecurityIsolation::roleCanBypassPolicies(
            ['rolsuper' => 'f', 'rolbypassrls' => 'f', 'rolreplication' => 't'],
        ));
        self::assertFalse(PostgresRowLevelSecurityIsolation::roleCanBypassPolicies(
            ['rolsuper' => 'f', 'rolbypassrls' => 'f', 'rolreplication' => 'f'],
        ));
    }

    /**
     * A REPLICATION role is refused, live — the P0 whose exploit was a working `pg_basebackup`.
     *
     * `twes_replicator` has `LOGIN REPLICATION` and nothing else: not superuser, not `BYPASSRLS`. Its SQL is
     * correctly policed, which is exactly what made the old check's clean verdict convincing, and the same
     * credentials copy every tenant's heap file.
     */
    public function testAConnectionWhoseRoleHasReplicationIsRefused(): void
    {
        $replicator = self::connectAs('TWES_TEST_DB_REPLICATOR_USER', 'TWES_TEST_DB_REPLICATOR_PASSWORD');

        // The precondition, asserted: it is neither of the two attributes that were already detected, so a
        // pass here would prove nothing about REPLICATION.
        $own = $replicator->query(
            'SELECT rolsuper OR rolbypassrls AS already_detected FROM pg_roles WHERE rolname = current_user',
        );
        self::assertNotFalse($own);
        self::assertNotContains($own->fetchColumn(), [true, 't', '1']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/REPLICATION/');

        new PostgresRowLevelSecurityIsolation()->assertConnectionCannotBypassPolicies($replicator);
    }

    /**
     * TRUNCATE reachable by MEMBERSHIP rather than inheritance — the second P0.
     *
     * `twes_truncator` is granted to the runtime role `WITH INHERIT FALSE`, so the runtime role does not hold
     * its privileges by default and `has_table_privilege()` answers "no", while one `SET ROLE` reaches them.
     * `current_user == session_user` throughout: no DSN trick is involved, which is what made this worse than
     * the ownership hole it sat two lines below.
     */
    public function testTruncateReachableOnlyBySetRoleIsRefused(): void
    {
        $truncator = getenv('TWES_TEST_DB_TRUNCATOR_ROLE');

        if (!\is_string($truncator)) {
            self::markTestSkipped('TWES_TEST_DB_TRUNCATOR_ROLE must be set.');
        }

        $this->owner->exec('GRANT TRUNCATE ON ' . self::TABLE . ' TO ' . $truncator);

        try {
            // The precondition that makes this a MEMBERSHIP test: the inheritance-based predicate the old
            // code used says the runtime role cannot truncate. If this ever becomes true, the grant stopped
            // being INHERIT FALSE and the test would pass for the wrong reason.
            $inherited = $this->connection->query(
                "SELECT has_table_privilege(current_user, '" . self::TABLE . "', 'TRUNCATE') AS inherited, "
                . 'current_user = session_user AS same_user',
            );
            self::assertNotFalse($inherited);
            /** @var array{inherited: bool|string, same_user: bool|string} $row */
            $row = $inherited->fetch(\PDO::FETCH_ASSOC);
            self::assertNotContains(
                $row['inherited'],
                [true, 't', '1'],
                'The grant is no longer WITH INHERIT FALSE, so this test no longer exercises the membership '
                . 'path that the inheritance-based predicate missed.',
            );
            self::assertContains($row['same_user'], [true, 't', '1']);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/TRUNCATEd/');

            PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection);
        } finally {
            $this->owner->exec('REVOKE TRUNCATE ON ' . self::TABLE . ' FROM ' . $truncator);
        }
    }

    /**
     * `bind()`'s read-back comparison, whose branch had NO coverage at all.
     *
     * Round 5 deleted the comparison and the whole suite stayed green: the test named
     * `testBindingIsVerifiedRatherThanAssumed` re-queries the GUC itself and never drives `bind()` into a
     * mismatch. Without it, `bind()` silently succeeds on a binding that never took — an unscoped connection
     * reporting success, which is the worst outcome this class has.
     *
     * Exercised through the extracted pure predicate, because provoking a genuine mismatch would mean making
     * PostgreSQL lie about its own `set_config` return value.
     */
    public function testTheBindingReadBackDetectsEveryWayAValueCanFailToTake(): void
    {
        $expected = self::TENANT_A;

        // The happy path: no message.
        self::assertNull(
            PostgresRowLevelSecurityIsolation::describeBindingMismatch($expected, $expected),
        );

        // A DIFFERENT tenant — the dangerous case, because the connection is scoped to somebody else.
        $wrong = PostgresRowLevelSecurityIsolation::describeBindingMismatch($expected, self::TENANT_B);
        self::assertNotNull($wrong);
        self::assertStringContainsString(self::TENANT_B, $wrong, 'The message must name what it actually read.');
        self::assertStringContainsString('did not take effect', $wrong);

        // Empty, and NULL — a GUC that was never written reads as one of these, so a naive `== ` comparison
        // (rather than `===`) would treat them as equal to a tenant id and report success.
        self::assertNotNull(PostgresRowLevelSecurityIsolation::describeBindingMismatch($expected, ''));
        self::assertNotNull(PostgresRowLevelSecurityIsolation::describeBindingMismatch($expected, null));

        // `false` is what PDO returns when the fetch itself failed. It must not be confused with a value, and
        // the message must say what type arrived rather than printing nothing.
        $failed = PostgresRowLevelSecurityIsolation::describeBindingMismatch($expected, false);
        self::assertNotNull($failed);
        self::assertStringContainsString('bool', $failed);

        // And a loose-comparison trap: 0 == 'string' was true in PHP 7 and is false in PHP 8, but `0` and
        // `'0'` still compare loosely equal, so a `!=` here would pass a zero off as a tenant id.
        self::assertNotNull(PostgresRowLevelSecurityIsolation::describeBindingMismatch('0', 0));
    }

    /**
     * `GRANT TRUNCATE ... TO PUBLIC` is caught — the grantee every role is covered by.
     *
     * `aclexplode` reports a PUBLIC grant as grantee OID **0**, which is not a real role, so
     * `pg_has_role(…, 0, 'MEMBER')` cannot find it and the PUBLIC term has to be tested separately. Without
     * this case that term was deletable with the suite green, and a single `GRANT TRUNCATE ON invoices TO
     * PUBLIC` in a migration would hand every role the ability to erase every tenant's rows.
     */
    public function testTruncateGrantedToPublicIsCaught(): void
    {
        $this->owner->exec('GRANT TRUNCATE ON ' . self::TABLE . ' TO PUBLIC');

        try {
            PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection);
            self::fail('TRUNCATE granted to PUBLIC must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('TRUNCATEd', $exception->getMessage());
        } finally {
            $this->owner->exec('REVOKE TRUNCATE ON ' . self::TABLE . ' FROM PUBLIC');
        }
    }

    /**
     * A policed PARTITIONED table is inspected, not skipped — the third P0.
     *
     * A partitioned parent carries `relkind = 'p'` and `relrowsecurity = t` while its partitions carry `f`,
     * so a `relkind = 'r'` filter dropped the whole table from the set: ownership, TRUNCATE, FORCE and the
     * non-vacuity count all skipped it. Round 5 read and wrote every tenant's rows through such a table
     * while the check reported clean.
     *
     * Two assertions, because counting it is not the same as policing it: the table must be SEEN (the count
     * rises), and a defect on it must be CAUGHT (unforced is refused).
     */
    public function testAPolicedPartitionedTableIsInspectedRatherThanSkipped(): void
    {
        $partitioned = self::TABLE . '_partitioned';

        $this->owner->exec('DROP TABLE IF EXISTS ' . $partitioned);
        $this->owner->exec(
            'CREATE TABLE ' . $partitioned . ' (
                company_id uuid NOT NULL,
                id         integer NOT NULL,
                label      text NOT NULL,
                PRIMARY KEY (company_id, id)
            ) PARTITION BY LIST (company_id)',
        );

        try {
            foreach (PostgresRowLevelSecurityIsolation::policySqlFor($partitioned) as $statement) {
                $this->owner->exec($statement);
            }

            // The precondition: PostgreSQL really does report 'p' here, and really does set relrowsecurity
            // on the parent. Asserted so this test cannot silently stop being about partitioning.
            $kind = $this->connection->query(
                "SELECT relkind, relrowsecurity FROM pg_class WHERE relname = '" . $partitioned . "'",
            );
            self::assertNotFalse($kind);
            /** @var array{relkind: string, relrowsecurity: bool|string} $meta */
            $meta = $kind->fetch(\PDO::FETCH_ASSOC);
            self::assertSame('p', $meta['relkind']);
            self::assertContains($meta['relrowsecurity'], [true, 't', '1']);

            // SEEN: three policed tables now — the two ordinary ones plus the partitioned parent.
            self::assertSame(
                3,
                PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection),
                'A policed partitioned table must be counted. If this is 2, relkind filtering has dropped '
                . 'it and every check below skips it too.',
            );

            // POLICED: a defect on it is caught rather than skipped.
            $this->owner->exec('ALTER TABLE ' . $partitioned . ' NO FORCE ROW LEVEL SECURITY');

            try {
                PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection);
                self::fail('An unforced policed PARTITIONED table must be refused.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString($partitioned, $exception->getMessage());
            }
        } finally {
            $this->owner->exec('DROP TABLE IF EXISTS ' . $partitioned);
        }
    }

    /**
     * The `session_user` term on the OWNERSHIP and TRUNCATE axes, which I wrongly called untestable.
     *
     * I recorded the single-half mutants as "equivalent mutants because the tests run with
     * `current_user == session_user`". That is true of the **`current_user`** halves — they are logically
     * redundant, since `SET ROLE` requires membership and `pg_has_role` is transitive. It is **false** of the
     * `session_user` halves, which are the load-bearing ones, and a reviewer distinguished them in one `psql`
     * call using roles this fixture already provisions.
     *
     * The distinguishing shape: connect as the runtime role with `options='-c role=twes_truncator'`, so
     * `current_user` is `twes_truncator` (a member of nothing) while `session_user` is the runtime role (a
     * member of the probe owner). Deleting the `session_user` term then reports clean while the connection is
     * one `SET ROLE` from the table's owner.
     *
     * The lesson is the finding: **a documented impossibility gets read once and never re-tested**, so
     * claiming one is more expensive than admitting a gap. "Equivalent mutant" means *no input distinguishes
     * it* — a much stronger claim than "the inputs I tried did not".
     */
    public function testOwnershipAndTruncateAreCheckedAgainstSessionUserNotOnlyCurrentUser(): void
    {
        $probeOwner = getenv('TWES_TEST_DB_PROBE_OWNER_ROLE');
        $truncator = getenv('TWES_TEST_DB_TRUNCATOR_ROLE');
        $runtimeRole = getenv('TWES_TEST_DB_USER');

        if (!\is_string($probeOwner) || !\is_string($truncator) || !\is_string($runtimeRole)) {
            self::markTestSkipped('The probe-owner, truncator and runtime role names must all be set.');
        }

        $owned = self::TABLE . '_session_user_probe';

        $this->owner->exec('GRANT ' . $probeOwner . ' TO ' . $runtimeRole . ' WITH INHERIT FALSE');
        $this->owner->exec('DROP TABLE IF EXISTS ' . $owned);
        $this->owner->exec(
            'CREATE TABLE ' . $owned . ' (company_id uuid NOT NULL, id integer NOT NULL, '
            . 'PRIMARY KEY (company_id, id))',
        );

        try {
            foreach (PostgresRowLevelSecurityIsolation::policySqlFor($owned) as $statement) {
                $this->owner->exec($statement);
            }

            $this->owner->exec('ALTER TABLE ' . $owned . ' OWNER TO ' . $probeOwner);

            // current_user CHANGED by the DSN, so the two differ — this is the whole point.
            $switched = self::connect("options='-c role=" . $truncator . "'");

            $identities = $switched->query(
                'SELECT current_user, session_user, '
                . "pg_has_role(session_user, '" . $probeOwner . "', 'MEMBER') AS session_reaches, "
                . "pg_has_role(current_user, '" . $probeOwner . "', 'MEMBER') AS current_reaches",
            );
            self::assertNotFalse($identities);
            /** @var array{current_user: string, session_user: string, session_reaches: bool|string, current_reaches: bool|string} $row */
            $row = $identities->fetch(\PDO::FETCH_ASSOC);

            // All three preconditions asserted, because each one failing would make this pass vacuously.
            self::assertNotSame($row['session_user'], $row['current_user'], 'the DSN role option took effect');
            self::assertContains($row['session_reaches'], [true, 't', '1'], 'session_user reaches the owner');
            self::assertNotContains(
                $row['current_reaches'],
                [true, 't', '1'],
                'current_user does NOT — so only the session_user term can catch this.',
            );

            // TRUNCATE on the SAME axis. Granted on the ordinary probe table — owned by twes_owner, which is
            // NOT reachable — so the only thing that can flag it is the TRUNCATE term, and only its
            // session_user half, since current_user reaches nothing.
            $this->owner->exec('GRANT TRUNCATE ON ' . self::TABLE . ' TO ' . $probeOwner);

            try {
                PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($switched);
                self::fail('An owner reachable only from session_user must be refused.');
            } catch (\RuntimeException $exception) {
                $message = $exception->getMessage();

                self::assertStringContainsString('owned by ' . $probeOwner, $message, 'ownership axis');
                self::assertStringContainsString(
                    self::TABLE . ' can be TRUNCATEd',
                    $message,
                    'TRUNCATE axis — reachable only from session_user, so only that term can catch it.',
                );
            } finally {
                $this->owner->exec('REVOKE TRUNCATE ON ' . self::TABLE . ' FROM ' . $probeOwner);
            }
        } finally {
            try {
                $this->owner->exec('SET ROLE ' . $probeOwner);
                $this->owner->exec('DROP TABLE IF EXISTS ' . $owned);
            } finally {
                $this->owner->exec('RESET ROLE');
                $this->owner->exec('DROP TABLE IF EXISTS ' . $owned);
                $this->owner->exec('REVOKE ' . $probeOwner . ' FROM ' . $runtimeRole);
            }
        }
    }

    /**
     * A policy whose two halves scope DIFFERENT columns is refused.
     *
     * Round 7's P0, and the sharpest one so far: each half was individually canonical, so the exact-match
     * comparison introduced a round earlier reported no violation — and a plain `INSERT` is guarded by
     * `WITH CHECK` alone, so tenant A planted a row in tenant B. Any denormalised tenant-ish column the
     * inserting session controls will do. "The column name is the only degree of freedom" has to mean one
     * degree per TABLE, not one per clause.
     *
     * My own test previously pinned the permissive reading as *intended*, using the same column on both
     * halves — so the mismatched pair had no case in either direction.
     */
    public function testAPolicyWhoseHalvesScopeDifferentColumnsIsRefused(): void
    {
        $table = self::TABLE . '_mismatched';

        $this->owner->exec('DROP TABLE IF EXISTS ' . $table);
        $this->owner->exec(
            'CREATE TABLE ' . $table . ' (company_id uuid NOT NULL, audit_tenant uuid NOT NULL, id integer '
            . 'NOT NULL, PRIMARY KEY (company_id, id))',
        );

        try {
            $this->owner->exec('ALTER TABLE ' . $table . ' ENABLE ROW LEVEL SECURITY');
            $this->owner->exec('ALTER TABLE ' . $table . ' FORCE ROW LEVEL SECURITY');

            // Hand-written rather than from policySqlFor(), because the point is that a hand-written policy
            // can be individually well-formed on each half and still wrong.
            $scoped = static fn(string $column): string => $column
                . " = nullif(current_setting('" . PostgresRowLevelSecurityIsolation::TENANT_SETTING
                . "', true), '')::uuid";

            $this->owner->exec(
                'CREATE POLICY tenant_isolation ON ' . $table
                . ' USING (' . $scoped('company_id') . ')'
                . ' WITH CHECK (' . $scoped('audit_tenant') . ')',
            );

            // The precondition that makes this the interesting case: BOTH halves are canonical on their own.
            self::assertTrue(
                PostgresRowLevelSecurityIsolation::policyExpressionIsCanonical(
                    PostgresRowLevelSecurityIsolation::canonicalPolicyExpression('company_id'),
                ),
            );
            self::assertTrue(
                PostgresRowLevelSecurityIsolation::policyExpressionIsCanonical(
                    PostgresRowLevelSecurityIsolation::canonicalPolicyExpression('audit_tenant'),
                ),
            );

            try {
                PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection);
                self::fail('A policy whose halves scope different columns must be refused.');
            } catch (\RuntimeException $exception) {
                $message = $exception->getMessage();

                // BOTH violations, asserted separately. A single mismatched policy trips the per-policy check
                // AND the cross-policy one, so asserting only the shared phrase let either be deleted while
                // the other kept the test green — which is exactly what the mutation sweep showed.
                self::assertStringContainsString(
                    'USING and WITH CHECK clauses scope DIFFERENT columns',
                    $message,
                    'the per-policy check',
                );
                self::assertStringContainsString(
                    'permissive policies scoping DIFFERENT columns',
                    $message,
                    'the cross-policy check',
                );
                self::assertStringContainsString('company_id vs audit_tenant', $message);
            }
        } finally {
            $this->owner->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /**
     * A legacy `INHERITS` child of a policed parent is inspected — the mechanism `pg_partition_tree` cannot see.
     *
     * PostgreSQL still fully supports table inheritance, and a child created that way has
     * `relispartition = f`, appears in no partition tree, and carries `relrowsecurity = f`. Round 6's fix
     * walked `pg_partition_tree` and so covered declarative partitioning only; round 7 read, updated, deleted
     * AND inserted across tenants through an `INHERITS` child with the verdict clean. `pg_inherits` is the
     * catalogue behind both mechanisms.
     */
    public function testAnInheritsChildOfAPolicedParentIsInspected(): void
    {
        $parent = self::TABLE . '_inh_parent';
        $child = self::TABLE . '_inh_child';

        $this->owner->exec('DROP TABLE IF EXISTS ' . $child);
        $this->owner->exec('DROP TABLE IF EXISTS ' . $parent . ' CASCADE');
        $this->owner->exec(
            'CREATE TABLE ' . $parent . ' (company_id uuid NOT NULL, id integer NOT NULL, '
            . 'PRIMARY KEY (company_id, id))',
        );

        try {
            foreach (PostgresRowLevelSecurityIsolation::policySqlFor($parent) as $statement) {
                $this->owner->exec($statement);
            }

            $this->owner->exec('CREATE TABLE ' . $child . ' () INHERITS (' . $parent . ')');

            // The preconditions: legacy inheritance, NOT a declarative partition, and unpoliced.
            $meta = $this->connection->query(
                "SELECT relispartition, relrowsecurity FROM pg_class WHERE relname = '" . $child . "'",
            );
            self::assertNotFalse($meta);
            /** @var array{relispartition: bool|string, relrowsecurity: bool|string} $row */
            $row = $meta->fetch(\PDO::FETCH_ASSOC);
            self::assertNotContains($row['relispartition'], [true, 't', '1'], 'not a declarative partition');
            self::assertNotContains($row['relrowsecurity'], [true, 't', '1'], 'unpoliced of its own');

            try {
                PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection);
                self::fail('An unpoliced INHERITS child of a policed parent must be refused.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString($child, $exception->getMessage());
                // And it must be NAMED correctly — calling an INHERITS child "a partition" was a separate
                // finding, from a column that was fetched and never read.
                self::assertStringContainsString('INHERITS child', $exception->getMessage());
            }
        } finally {
            $this->owner->exec('DROP TABLE IF EXISTS ' . $child);
            $this->owner->exec('DROP TABLE IF EXISTS ' . $parent . ' CASCADE');
        }
    }

    /**
     * The SIXTH bypass class: an unpoliced **ANCESTOR** of a policed table.
     *
     * Every arm of the subject set before this one walked `pg_inherits` **downward** — `d.oid = i.inhparent`
     * emitting `i.inhrelid` — so it could only ever reach descendants. The inverse was never inspected, and
     * PostgreSQL's inheritance semantics make it the more dangerous direction: *"policies belonging to child
     * tables are not applied when accessing through the parent"*. So an unpoliced parent of policed children
     * returns **every descendant's rows to every tenant**, and accepts writes into any of them, while the
     * children themselves are correctly policed and the verdict reads `CLEAN — N policed table(s) inspected`.
     *
     * It is not a contrived shape. It is the natural Wave 1 supertype — a `documents` parent with `invoices`
     * and `credit_notes` inheriting it — where somebody polices the leaves because those are the tables with
     * the data in them.
     *
     * The leak is demonstrated on a real connection BEFORE the check is asked about it, because a test that
     * only asserts the error message proves the message and not the danger.
     */
    public function testAnUnpolicedAncestorOfAPolicedTableIsInspected(): void
    {
        $parent = self::TABLE . '_anc_parent';
        $child = self::TABLE . '_anc_child';
        $runtimeRole = getenv('TWES_TEST_DB_USER');

        $this->owner->exec('DROP TABLE IF EXISTS ' . $child);
        $this->owner->exec('DROP TABLE IF EXISTS ' . $parent . ' CASCADE');
        $this->owner->exec(
            'CREATE TABLE ' . $parent . ' (company_id uuid NOT NULL, id integer NOT NULL, '
            . 'PRIMARY KEY (company_id, id))',
        );

        try {
            $this->owner->exec('CREATE TABLE ' . $child . ' () INHERITS (' . $parent . ')');

            // The CHILD is policed, correctly and from the canonical emitter. The PARENT deliberately is not
            // — that asymmetry is the whole subject of this test.
            foreach (PostgresRowLevelSecurityIsolation::policySqlFor($child) as $statement) {
                $this->owner->exec($statement);
            }

            if (\is_string($runtimeRole)) {
                $this->owner->exec('GRANT SELECT, INSERT ON ' . $parent . ' TO ' . $runtimeRole);
                $this->owner->exec('GRANT SELECT, INSERT ON ' . $child . ' TO ' . $runtimeRole);
            }

            $isolation = new PostgresRowLevelSecurityIsolation();

            $this->connection->beginTransaction();
            $isolation->bind($this->connection, InMemoryTenantContext::forTenant(TenantId::fromString(self::TENANT_A)));
            $this->connection->exec(
                'INSERT INTO ' . $child . " (company_id, id) VALUES ('" . self::TENANT_A . "', 1)",
            );
            $this->connection->commit();

            // Now as tenant B. The child refuses — its policy is right. The parent does not.
            $this->connection->beginTransaction();
            $isolation->bind($this->connection, InMemoryTenantContext::forTenant(TenantId::fromString(self::TENANT_B)));

            $direct = $this->connection->query('SELECT count(*) FROM ' . $child);
            self::assertNotFalse($direct);
            self::assertSame(
                '0',
                (string) $direct->fetchColumn(),
                'The child is policed, so tenant B must not see tenant A row through it.',
            );

            $through = $this->connection->query('SELECT count(*) FROM ' . $parent);
            self::assertNotFalse($through);
            self::assertSame(
                '1',
                (string) $through->fetchColumn(),
                'THE LEAK: a parent carries no policy of its own, and a child\'s policy is not applied when '
                . 'the child is read through the parent — so tenant B reads tenant A row.',
            );

            $this->connection->commit();

            // Captured rather than asserted inside the catch: PHPUnit's AssertionFailedError extends
            // \RuntimeException, so `self::fail()` in a try whose catch is \RuntimeException is swallowed by
            // its own handler. That mistake is recorded in CLAUDE.md and has been made twice here already.
            $caught = null;

            try {
                PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection);
            } catch (\RuntimeException $exception) {
                $caught = $exception;
            }

            self::assertNotNull($caught, 'An unpoliced ANCESTOR of a policed table must be refused.');
            self::assertStringContainsString($parent, $caught->getMessage());
            // Named by the relationship that makes it dangerous. Calling this "a child" would send a reader
            // to police the leaves, which are already policed.
            self::assertStringContainsStringIgnoringCase('ancestor', $caught->getMessage());

            // And the correct configuration must be ACCEPTED, or the check forbids inheritance outright
            // rather than requiring that every table in the hierarchy be policed.
            foreach (PostgresRowLevelSecurityIsolation::policySqlFor($parent) as $statement) {
                $this->owner->exec($statement);
            }

            self::assertSame(
                4,
                PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection),
                'Two probe tables plus this parent and child — all four inspected.',
            );
        } finally {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            $this->owner->exec('DROP TABLE IF EXISTS ' . $child);
            $this->owner->exec('DROP TABLE IF EXISTS ' . $parent . ' CASCADE');
        }
    }

    /**
     * The FIFTH path: an object that borrows a privileged role's exemption.
     *
     * Every other check in this class asks about roles *reachable* from the connection. PostgreSQL will run
     * part of a query as a role you cannot reach: a view evaluates policies as its OWNER unless
     * `security_invoker = true`, and that option defaults to **false**. Round 7 read and wrote across tenants
     * through a view owned by `twes_bypass` — a role this fixture already provisions and never grants to the
     * runtime role, which is precisely why the role queries never looked at it.
     *
     * Both directions, because the precedence is easy to get backwards: a view WITH `security_invoker` is safe
     * even when a privileged role owns it, and the first version of this check refused exactly that.
     */
    public function testAViewThatBorrowsAnExemptOwnersRlsBypassIsRefused(): void
    {
        $unsafe = self::TABLE . '_unsafe_view';
        $safe = self::TABLE . '_safe_view';

        $this->owner->exec('DROP VIEW IF EXISTS ' . $unsafe);
        $this->owner->exec('DROP VIEW IF EXISTS ' . $safe);

        try {
            $this->owner->exec('CREATE VIEW ' . $unsafe . ' AS SELECT * FROM ' . self::TABLE);
            $runtimeRole = getenv('TWES_TEST_DB_USER');
            self::assertIsString($runtimeRole);
            $this->owner->exec('GRANT SELECT ON ' . $unsafe . ' TO ' . $runtimeRole);

            try {
                PostgresRowLevelSecurityIsolation::assertNoRlsExemptObjectIsReadable($this->connection);
                self::fail('A view without security_invoker must be refused.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString($unsafe, $exception->getMessage());
                self::assertStringContainsString('without security_invoker', $exception->getMessage());
            }

            // AND through the composite entry point, because a check nobody calls is not a check — removing
            // the call site from assertConnectionCannotBypassPolicies() was invisible to the direct test above.
            try {
                new PostgresRowLevelSecurityIsolation()->assertConnectionCannotBypassPolicies($this->connection);
                self::fail('assertConnectionCannotBypassPolicies() must include the object check.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString($unsafe, $exception->getMessage());
            }

            // The SAFE shape must be accepted, or the check forbids views outright rather than requiring they
            // be written correctly.
            $this->owner->exec('DROP VIEW ' . $unsafe);
            $this->owner->exec(
                'CREATE VIEW ' . $safe . ' WITH (security_invoker = true) AS SELECT * FROM ' . self::TABLE,
            );
            $this->owner->exec('GRANT SELECT ON ' . $safe . ' TO ' . $runtimeRole);

            PostgresRowLevelSecurityIsolation::assertNoRlsExemptObjectIsReadable($this->connection);

            self::assertTrue(true, 'A security_invoker view is accepted.');
        } finally {
            $this->owner->exec('DROP VIEW IF EXISTS ' . $unsafe);
            $this->owner->exec('DROP VIEW IF EXISTS ' . $safe);
        }
    }

    /**
     * A `SECURITY DEFINER` function owned by an exempt role is refused.
     *
     * The third shape of the fifth path, and it lives in `pg_proc` rather than `pg_class`, so it needs its own
     * query — which means it can be neutered independently. Owned by a superuser here because that is the case
     * that matters: the function body then runs with row security not applying at all.
     */
    public function testASecurityDefinerFunctionOwnedByAnExemptRoleIsRefused(): void
    {
        $granter = self::superuserConnection();

        if (null === $granter) {
            self::markTestSkipped('No superuser connection available to own a SECURITY DEFINER function.');
        }

        $runtimeRole = getenv('TWES_TEST_DB_USER');
        self::assertIsString($runtimeRole);

        $function = self::TABLE . '_secdef';

        $granter->exec('DROP FUNCTION IF EXISTS ' . $function . '()');
        $granter->exec(
            'CREATE FUNCTION ' . $function . '() RETURNS bigint LANGUAGE sql SECURITY DEFINER '
            . 'AS \'SELECT count(*) FROM ' . self::TABLE . '\'',
        );

        try {
            $granter->exec('GRANT EXECUTE ON FUNCTION ' . $function . '() TO ' . $runtimeRole);

            try {
                PostgresRowLevelSecurityIsolation::assertNoRlsExemptObjectIsReadable($this->connection);
                self::fail('A SECURITY DEFINER function owned by an exempt role must be refused.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString($function, $exception->getMessage());
                self::assertStringContainsString('SECURITY DEFINER', $exception->getMessage());
            }
        } finally {
            $granter->exec('DROP FUNCTION IF EXISTS ' . $function . '()');
        }
    }

    /**
     * A `SECURITY DEFINER` function owned by the TABLE OWNER — neither superuser nor `BYPASSRLS` — is refused.
     *
     * The check was filtered to `rolsuper OR rolbypassrls`, which reads as thorough and misses the owner that
     * matters most in this project. `twes_owner` holds neither attribute and **owns every policed table**, so
     * wherever `FORCE ROW LEVEL SECURITY` is absent it is exempt from its own policies — and a `SECURITY
     * DEFINER` function it owns hands that exemption to any caller. `twes_owner` is deliberately never granted
     * to the runtime role, which is exactly what makes the call an escalation rather than a convenience.
     *
     * No superuser is needed to arrange this, unlike the exempt-owner case above: the owning connection the
     * suite already has is the dangerous owner. So this case runs on every machine, where that one skips.
     *
     * It also pins the second half of the same fix — a function's DEFAULT ACL grants `EXECUTE` to **PUBLIC**,
     * so no grant is issued here at all. Replacing `has_function_privilege` with an ACL walk that read a NULL
     * `proacl` as "no grants" would have made every untouched SECURITY DEFINER function invisible.
     */
    public function testASecurityDefinerFunctionOwnedByTheTableOwnerIsRefused(): void
    {
        $function = self::TABLE . '_secdef_owner';

        $this->owner->exec('DROP FUNCTION IF EXISTS ' . $function . '()');
        $this->owner->exec(
            'CREATE FUNCTION ' . $function . '() RETURNS bigint LANGUAGE sql SECURITY DEFINER '
            . 'AS \'SELECT count(*) FROM ' . self::TABLE . '\'',
        );

        try {
            // The preconditions: the owner is NOT exempt, and no EXECUTE grant was made.
            $attributes = $this->connection->query(
                'SELECT o.rolsuper OR o.rolbypassrls AS exempt, p.proacl IS NULL AS default_acl '
                . 'FROM pg_proc p JOIN pg_roles o ON o.oid = p.proowner '
                . "WHERE p.proname = '" . $function . "'",
            );
            self::assertNotFalse($attributes);
            /** @var array{exempt: bool|string, default_acl: bool|string} $row */
            $row = $attributes->fetch(\PDO::FETCH_ASSOC);
            self::assertNotContains($row['exempt'], [true, 't', '1'], 'the owner must NOT be exempt');
            self::assertContains($row['default_acl'], [true, 't', '1'], 'no EXECUTE grant was made');

            $caught = null;

            try {
                PostgresRowLevelSecurityIsolation::assertNoRlsExemptObjectIsReadable($this->connection);
            } catch (\RuntimeException $exception) {
                $caught = $exception;
            }

            self::assertNotNull(
                $caught,
                'A SECURITY DEFINER function owned by the unreachable table owner must be refused.',
            );
            self::assertStringContainsString($function, $caught->getMessage());
            // And the reason must be the right one: naming it "exempt" would send a reader to check role
            // attributes that are correct, rather than to the ownership that is the problem.
            self::assertStringContainsString('cannot otherwise become', $caught->getMessage());
        } finally {
            $this->owner->exec('DROP FUNCTION IF EXISTS ' . $function . '()');
        }
    }

    /**
     * A view readable only through a `WITH INHERIT FALSE` grant is inspected.
     *
     * `has_table_privilege` resolves privileges *inheritably*, so a SELECT grant made to a role the connection
     * is a MEMBER of but does not INHERIT from is invisible to it — while `SET ROLE` needs only the membership.
     * Round 11 found the view query still filtered on that function, seven rounds after the same mistake was
     * removed from the table query, so a leaking view was excluded from the result set and the verdict read
     * clean. `twes_probe_owner` is used because the provisioning script grants it to the owning role `WITH
     * ADMIN OPTION`, which is what lets this test make the `WITH INHERIT FALSE` grant without a superuser —
     * exactly the reason that role exists. What it is named for is irrelevant here; only the grant shape is.
     */
    public function testAViewReachableOnlyByNonInheritedMembershipIsInspected(): void
    {
        $view = self::TABLE . '_noninherit_view';
        $probeRole = getenv('TWES_TEST_DB_PROBE_OWNER_ROLE');
        $runtimeRole = getenv('TWES_TEST_DB_USER');

        if (!\is_string($probeRole) || !\is_string($runtimeRole)) {
            self::markTestSkipped('No non-inheriting probe role configured.');
        }

        $this->owner->exec('DROP VIEW IF EXISTS ' . $view);
        // Owned by the owner, security_invoker unset — the leaking shape.
        $this->owner->exec('CREATE VIEW ' . $view . ' AS SELECT * FROM ' . self::TABLE);

        try {
            // REVOKED FIRST, and this is the step that makes the test about anything. The provisioning script
            // sets ALTER DEFAULT PRIVILEGES granting the runtime role SELECT on every relation the owner
            // creates, so without this the role holds SELECT directly and inheritably — the blind function
            // answers YES, the premise below is unreachable, and the case proves nothing.
            $this->owner->exec('REVOKE ALL ON ' . $view . ' FROM ' . $runtimeRole);
            $this->owner->exec('GRANT ' . $probeRole . ' TO ' . $runtimeRole . ' WITH INHERIT FALSE');
            $this->owner->exec('GRANT SELECT ON ' . $view . ' TO ' . $probeRole);

            // The precondition, asserted rather than assumed: the blind function says NO while membership
            // says YES. If PostgreSQL ever changes that, this test is about nothing and should say so.
            $resolution = $this->connection->query(
                "SELECT has_table_privilege(current_user, '" . $view . "', 'SELECT') AS inheritable, "
                . "pg_has_role(session_user, '" . $probeRole . "', 'MEMBER') AS reachable",
            );
            self::assertNotFalse($resolution);
            /** @var array{inheritable: bool|string, reachable: bool|string} $flags */
            $flags = $resolution->fetch(\PDO::FETCH_ASSOC);
            self::assertNotContains($flags['inheritable'], [true, 't', '1'], 'not held inheritably');
            self::assertContains($flags['reachable'], [true, 't', '1'], 'but one SET ROLE away');

            $caught = null;

            try {
                PostgresRowLevelSecurityIsolation::assertNoRlsExemptObjectIsReadable($this->connection);
            } catch (\RuntimeException $exception) {
                $caught = $exception;
            }

            self::assertNotNull($caught, 'A view reachable by non-inherited membership must be inspected.');
            self::assertStringContainsString($view, $caught->getMessage());
        } finally {
            $this->owner->exec('DROP VIEW IF EXISTS ' . $view);
            $this->owner->exec('REVOKE ' . $probeRole . ' FROM ' . $runtimeRole);
        }
    }

    /**
     * A leaking view reachable only by a COLUMN grant is inspected — the eighth channel.
     *
     * Column privileges do not live in `pg_class.relacl`; they live in `pg_attribute.attacl`, and a column
     * grant is recorded there and nowhere in `relacl`. So the object filter — which walked `relacl` only —
     * excluded a non-`security_invoker` view from its result set entirely, and the verdict read CLEAN while
     * the runtime role read every tenant through it. [Verified on this server: after `GRANT SELECT (label)`,
     * `relacl` records no SELECT for the role, `has_table_privilege` is false, `has_column_privilege` is
     * true, and `attacl` reads `label={twes=r/postgres}`.]
     *
     * The pre-round-11 `has_table_privilege` had the identical hole, so this is a gap of long standing rather
     * than a regression — which is exactly why a rewrite of that line that did not widen it needs a test.
     */
    public function testAViewReachableOnlyByAColumnGrantIsInspected(): void
    {
        $view = self::TABLE . '_colgrant_view';
        $runtimeRole = getenv('TWES_TEST_DB_USER');
        self::assertIsString($runtimeRole);

        $this->owner->exec('DROP VIEW IF EXISTS ' . $view);
        $this->owner->exec('CREATE VIEW ' . $view . ' AS SELECT * FROM ' . self::TABLE);

        try {
            // Table-level access removed, column-level access granted. The provisioning script's default
            // privileges hand the runtime role table-level SELECT on everything the owner creates, so without
            // this REVOKE the case would pass through the arm that already worked and prove nothing.
            $this->owner->exec('REVOKE ALL ON ' . $view . ' FROM ' . $runtimeRole);
            $this->owner->exec('GRANT SELECT (label) ON ' . $view . ' TO ' . $runtimeRole);

            $shape = $this->connection->query(
                'SELECT (SELECT relacl IS NULL FROM pg_class WHERE oid = \'' . $view . '\'::regclass) AS no_relacl, '
                . 'has_table_privilege(current_user, \'' . $view . '\', \'SELECT\') AS table_level, '
                . 'has_column_privilege(current_user, \'' . $view . '\', \'label\', \'SELECT\') AS column_level',
            );
            self::assertNotFalse($shape);
            /** @var array{no_relacl: bool|string, table_level: bool|string, column_level: bool|string} $flags */
            $flags = $shape->fetch(\PDO::FETCH_ASSOC);
            // NOT asserted as "relacl is NULL": the REVOKE above materialises the default ACL, so relacl is
            // non-NULL and simply carries no SELECT for this role. The hole is the same either way — the
            // grant that DOES exist lives in pg_attribute.attacl, which the filter never reads.
            self::assertNotContains($flags['no_relacl'], [true, 't', '1'], 'REVOKE materialised the ACL');
            self::assertNotContains($flags['table_level'], [true, 't', '1'], 'no table-level privilege');
            self::assertContains($flags['column_level'], [true, 't', '1'], 'but the column IS readable');

            $caught = null;

            try {
                PostgresRowLevelSecurityIsolation::assertNoRlsExemptObjectIsReadable($this->connection);
            } catch (\RuntimeException $exception) {
                $caught = $exception;
            }

            self::assertNotNull($caught, 'A view reachable by a COLUMN grant must be inspected.');
            self::assertStringContainsString($view, $caught->getMessage());
        } finally {
            $this->owner->exec('DROP VIEW IF EXISTS ' . $view);
        }
    }

    /**
     * A leaking view granted only a WRITE privilege is inspected — the same filter, the other direction.
     *
     * The filter asked only about `SELECT`, and a cross-tenant WRITE needs none. Writes through a view without
     * `security_invoker` execute with the VIEW OWNER's privileges and the base table's policies are evaluated
     * as that owner, so an `INSERT ... VALUES` — which requires no read privilege at all — plants a row in
     * whatever tenant the caller names. An insert-only journal or audit view is an ordinary shape, and it was
     * excluded from the result set before it ever reached the classifier that would have flagged it.
     *
     * "It is only a write" is not a mitigation: `UPDATE`/`DELETE` give cross-tenant overwrite and erase, and
     * `UPDATE ... RETURNING` gives the read back as well.
     */
    public function testAViewGrantedOnlyAWritePrivilegeIsInspected(): void
    {
        $view = self::TABLE . '_writeonly_view';
        $runtimeRole = getenv('TWES_TEST_DB_USER');
        self::assertIsString($runtimeRole);

        $this->owner->exec('DROP VIEW IF EXISTS ' . $view);
        $this->owner->exec('CREATE VIEW ' . $view . ' AS SELECT * FROM ' . self::TABLE);

        try {
            $this->owner->exec('REVOKE ALL ON ' . $view . ' FROM ' . $runtimeRole);
            $this->owner->exec('GRANT INSERT ON ' . $view . ' TO ' . $runtimeRole);

            $shape = $this->connection->query(
                'SELECT has_table_privilege(current_user, \'' . $view . '\', \'SELECT\') AS can_read, '
                . 'has_table_privilege(current_user, \'' . $view . '\', \'INSERT\') AS can_write',
            );
            self::assertNotFalse($shape);
            /** @var array{can_read: bool|string, can_write: bool|string} $flags */
            $flags = $shape->fetch(\PDO::FETCH_ASSOC);
            self::assertNotContains($flags['can_read'], [true, 't', '1'], 'deliberately NOT readable');
            self::assertContains($flags['can_write'], [true, 't', '1'], 'but writable, which is enough');

            $caught = null;

            try {
                PostgresRowLevelSecurityIsolation::assertNoRlsExemptObjectIsReadable($this->connection);
            } catch (\RuntimeException $exception) {
                $caught = $exception;
            }

            self::assertNotNull($caught, 'A view granted only INSERT must be inspected.');
            self::assertStringContainsString($view, $caught->getMessage());
        } finally {
            $this->owner->exec('DROP VIEW IF EXISTS ' . $view);
        }
    }

    /**
     * The `SECURITY DEFINER` classifier, as a pure function — both reasons, distinctly worded.
     *
     * The wording is load-bearing rather than cosmetic. Reporting an unreachable-but-unprivileged owner as
     * "EXEMPT (superuser or BYPASSRLS)" sends a reader to check two role attributes that are correct, and they
     * conclude the finding is a false positive. That is how a real bypass gets closed as noise.
     */
    public function testTheSecurityDefinerClassifierNamesTheReasonForEachOwner(): void
    {
        $violations = PostgresRowLevelSecurityIsolation::securityDefinerFunctionViolations([
            ['function' => 'public.super_fn', 'owner' => 'postgres', 'owner_exempt' => 't'],
            ['function' => 'public.owner_fn', 'owner' => 'twes_owner', 'owner_exempt' => 'f'],
        ]);

        self::assertCount(2, $violations);
        self::assertStringContainsString('EXEMPT from row-level security', $violations[0]);
        self::assertStringContainsString('postgres', $violations[0]);
        self::assertStringContainsString('cannot otherwise become', $violations[1]);
        self::assertStringNotContainsString('EXEMPT', $violations[1]);
        self::assertStringContainsString('FORCE ROW LEVEL SECURITY', $violations[1]);

        self::assertSame([], PostgresRowLevelSecurityIsolation::securityDefinerFunctionViolations([]));
    }

    /**
     * The session-lifetime classifier, as a pure function, over every relation kind it can be handed.
     *
     * Arranging a temporary *sequence*, *view* or *composite type* on one connection is a statement each and
     * proves nothing extra about the danger, so the kind vocabulary is pinned here while the dangerous pair —
     * a temporary table and a held cursor — is proven live above. The `default` arm is exercised too: a kind
     * this match does not know must still be REPORTED, with the raw letter, rather than silently named
     * something it is not.
     */
    public function testTheSessionLifetimeClassifierNamesEveryRelationKind(): void
    {
        $violations = PostgresRowLevelSecurityIsolation::sessionLifetimeDataViolations([
            ['name' => 'tmp_t', 'kind' => 'r'],
            ['name' => 'tmp_v', 'kind' => 'v'],
            ['name' => 'tmp_s', 'kind' => 'S'],
            ['name' => 'tmp_m', 'kind' => 'm'],
            ['name' => 'tmp_c', 'kind' => 'c'],
            ['name' => 'tmp_i', 'kind' => 'i'],
            ['name' => 'tmp_x', 'kind' => 'Z'],
        ], [
            ['name' => 'held_one'],
        ]);

        self::assertCount(8, $violations);
        self::assertStringContainsString('temporary table tmp_t', $violations[0]);
        self::assertStringContainsString('temporary view tmp_v', $violations[1]);
        self::assertStringContainsString('temporary sequence tmp_s', $violations[2]);
        self::assertStringContainsString('temporary materialised view tmp_m', $violations[3]);
        self::assertStringContainsString('temporary composite type tmp_c', $violations[4]);
        self::assertStringContainsString('temporary index tmp_i', $violations[5]);
        self::assertStringContainsString('relation of kind Z tmp_x', $violations[6]);
        self::assertStringContainsString('held cursor held_one', $violations[7]);

        // And a connection with nothing on it is clean — the guard must be satisfiable.
        self::assertSame([], PostgresRowLevelSecurityIsolation::sessionLifetimeDataViolations([], []));
    }

    /**
     * The object-exemption classifier, as a pure function — including the precedence that produced a false
     * positive on the first attempt.
     */
    public function testTheRlsExemptObjectPredicateClassifiesEveryShape(): void
    {
        $view = [
            'object' => 'public.v',
            'kind' => 'v',
            'owner' => 'twes_owner',
            'owner_exempt' => 'f',
            'security_invoker' => 't',
            'owned_by_caller' => 'f',
        ];

        // security_invoker wins over ownership: RLS applies as the QUERYING role, so an exempt owner is
        // irrelevant. Refusing this shape was the first version's bug.
        self::assertSame([], PostgresRowLevelSecurityIsolation::rlsExemptObjectViolations([$view]));
        self::assertSame([], PostgresRowLevelSecurityIsolation::rlsExemptObjectViolations(
            [['owner_exempt' => 't', 'owner' => 'postgres'] + $view],
        ));

        // Without it, the view evaluates as its owner — refused whoever that is, because it is not the
        // caller's scope; and the message escalates when the owner is exempt.
        $plain = PostgresRowLevelSecurityIsolation::rlsExemptObjectViolations(
            [['security_invoker' => 'f'] + $view],
        );
        self::assertCount(1, $plain);
        self::assertStringContainsString("not the caller's", $plain[0]);

        $exempt = PostgresRowLevelSecurityIsolation::rlsExemptObjectViolations(
            [['security_invoker' => 'f', 'owner_exempt' => 't', 'owner' => 'postgres'] + $view],
        );
        self::assertCount(1, $exempt);
        self::assertStringContainsString('EXEMPT', $exempt[0]);

        // A matview and a foreign table cannot carry RLS at any ownership, so they are refused on kind alone —
        // even with security_invoker set, which is meaningless for them.
        foreach (['m' => 'materialised view', 'f' => 'foreign table'] as $kind => $described) {
            $refused = PostgresRowLevelSecurityIsolation::rlsExemptObjectViolations(
                [['kind' => $kind, 'security_invoker' => 't'] + $view],
            );
            self::assertCount(1, $refused, $described);
            self::assertStringContainsString($described, $refused[0]);
        }
    }

    /**
     * A real PARTITION of a policed parent, which is the case my own previous test did not create.
     *
     * The test that closed R5-3 built a partitioned parent with **zero partitions**, so the interesting
     * relation never existed. Round 6 showed why that matters: a partition carries `relrowsecurity = f` of its
     * own and a parent's policy does **not** cover direct access to it, so `SELECT * FROM invoices_2026`
     * bypasses the policy entirely — tenant A could read, overwrite and delete tenant B's rows through one
     * while the check reported clean. No `relkind` list can ever reach them, because they are excluded by the
     * RLS flag rather than by kind; the inspected set has to walk `pg_partition_tree`.
     */
    public function testAPartitionOfAPolicedParentIsInspectedAndRefusedWhenUnpoliced(): void
    {
        $parent = self::TABLE . '_parted';
        $partition = $parent . '_a';

        $this->owner->exec('DROP TABLE IF EXISTS ' . $parent . ' CASCADE');
        $this->owner->exec(
            'CREATE TABLE ' . $parent . ' (company_id uuid NOT NULL, id integer NOT NULL, '
            . 'PRIMARY KEY (company_id, id)) PARTITION BY LIST (company_id)',
        );

        try {
            $this->owner->exec(
                'CREATE TABLE ' . $partition . ' PARTITION OF ' . $parent
                . " FOR VALUES IN ('" . self::TENANT_A . "')",
            );

            // Policy on the PARENT only — the natural, and wrong, thing a migration does.
            foreach (PostgresRowLevelSecurityIsolation::policySqlFor($parent) as $statement) {
                $this->owner->exec($statement);
            }

            // The precondition: PostgreSQL really does leave the partition unpoliced.
            $flags = $this->connection->query(
                'SELECT relrowsecurity FROM pg_class WHERE relname = \'' . $partition . '\'',
            );
            self::assertNotFalse($flags);
            self::assertNotContains(
                $flags->fetchColumn(),
                [true, 't', '1'],
                'The partition must be unpoliced, or this test is not about the blind spot.',
            );

            try {
                PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection);
                self::fail('An unpoliced partition of a policed parent must be refused.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString($partition, $exception->getMessage());
                self::assertStringContainsString('no row-level security of its own', $exception->getMessage());
            }

            // And the correct configuration — the policy on the partition too — must be ACCEPTED, or the
            // check would forbid partitioning outright rather than requiring it be done properly.
            foreach (PostgresRowLevelSecurityIsolation::policySqlFor($partition) as $statement) {
                $this->owner->exec($statement);
            }

            self::assertSame(
                4,
                PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection),
                'Two probe tables, the partitioned parent and its partition — all four inspected.',
            );
        } finally {
            $this->owner->exec('DROP TABLE IF EXISTS ' . $parent . ' CASCADE');
        }
    }

    /**
     * `bind()`'s read-back THROW, not just its comparison — the residue I wrongly called impossible.
     *
     * I recorded that provoking a mismatch "would need PostgreSQL to lie about its own `set_config` return
     * value". A reviewer refuted that in nine lines: PDO substitutes the statement class natively, so a
     * `PDOStatement` subclass whose `fetchColumn()` returns something else drives the branch on a real
     * connection against the real query. The lesson is bigger than the two lines — **a documented
     * impossibility gets read once and never re-tested**, so claiming one is more expensive than admitting a
     * gap.
     */
    public function testBindRaisesWhenTheReadBackDisagrees(): void
    {
        LyingStatement::reset();
        $lying = self::connect();
        $lying->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [LyingStatement::class]);
        $lying->beginTransaction();

        try {
            new PostgresRowLevelSecurityIsolation()->bind($lying, InMemoryTenantContext::forTenant(
                TenantId::fromString(self::TENANT_A),
            ));
            self::fail('bind() must refuse when the value it reads back is not the value it wrote.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('did not take effect', $exception->getMessage());
            self::assertStringContainsString(LyingStatement::WRONG_VALUE, $exception->getMessage());
        } finally {
            $lying->rollBack();
        }
    }

    /**
     * `ENABLE` + `FORCE` + a policy that isolates nothing is refused, live.
     *
     * Both flags satisfied and a policy present named exactly what a migration would name it — and
     * `USING (true)` inside. The earlier check read only the flags, so this was a clean verdict on a table
     * readable and writable across tenants.
     */
    public function testAPolicyThatIsolatesNothingIsRefused(): void
    {
        $this->owner->exec('DROP POLICY tenant_isolation ON ' . self::TABLE);
        $this->owner->exec('CREATE POLICY tenant_isolation ON ' . self::TABLE . ' USING (true)');

        try {
            // The precondition: both flags still say "policed", which is why reading them was insufficient.
            $flags = $this->connection->query(
                'SELECT relrowsecurity AND relforcerowsecurity AS looks_policed FROM pg_class '
                . "WHERE relname = '" . self::TABLE . "'",
            );
            self::assertNotFalse($flags);
            self::assertContains($flags->fetchColumn(), [true, 't', '1']);

            PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection);
            self::fail('A USING (true) policy must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('not the canonical tenant predicate', $exception->getMessage());
        } finally {
            $this->owner->exec('DROP POLICY IF EXISTS tenant_isolation ON ' . self::TABLE);

            foreach (PostgresRowLevelSecurityIsolation::policySqlFor(self::TABLE) as $statement) {
                // ENABLE/FORCE are idempotent; the CREATE POLICY is what matters here.
                try {
                    $this->owner->exec($statement);
                } catch (\PDOException) {
                    // already enabled
                }
            }
        }
    }

    /**
     * Every way a policed table can be reached around, as a pure function over catalogue rows.
     *
     * The live tests prove the query and the wiring; this pins the classification, including the `t`/`f`
     * string spellings that are the difference between a violation and a clean bill.
     */
    public function testThePolicedTableViolationPredicateClassifiesEveryReachableShape(): void
    {
        $canonical = PostgresRowLevelSecurityIsolation::canonicalPolicyExpression();
        $safe = [
            'table' => 'public.invoices',
            'owner' => 'twes_owner',
            'owner_reachable' => 'f',
            'can_truncate' => 'f',
            'forced' => 't',
            'rls_enabled' => 't',
            'is_partition' => 'f',
            'policies' => json_encode([
                ['qual' => $canonical, 'check' => $canonical, 'permissive' => true],
            ], \JSON_THROW_ON_ERROR),
        ];

        self::assertSame([], PostgresRowLevelSecurityIsolation::policedTableViolations([$safe]));

        $owned = PostgresRowLevelSecurityIsolation::policedTableViolations(
            [['owner_reachable' => 't'] + $safe],
        );
        self::assertCount(1, $owned);
        self::assertStringContainsString('owned by twes_owner', $owned[0]);

        $truncatable = PostgresRowLevelSecurityIsolation::policedTableViolations(
            [['can_truncate' => true] + $safe],
        );
        self::assertCount(1, $truncatable);
        self::assertStringContainsString('TRUNCATEd', $truncatable[0]);

        $unforced = PostgresRowLevelSecurityIsolation::policedTableViolations(
            [['forced' => false] + $safe],
        );
        self::assertCount(1, $unforced);
        self::assertStringContainsString('not FORCEd', $unforced[0]);

        // A PARTITION of a policed parent with RLS off on itself: a parent's policy does not cover direct
        // access to a partition, so this is a full cross-tenant read AND write.
        $unpoliced = PostgresRowLevelSecurityIsolation::policedTableViolations(
            [['rls_enabled' => 'f', 'is_partition' => 't'] + $safe],
        );
        self::assertCount(1, $unpoliced, 'and nothing further, since an unpoliced relation has no policies');
        self::assertStringContainsString('no row-level security of its own', $unpoliced[0]);

        // ---- the policy EXPRESSION, both halves ------------------------------------------------------
        // WITH CHECK (true) with a correct USING. PostgreSQL reuses USING as a write check for UPDATE and
        // INSERT ... RETURNING only, so a plain INSERT is guarded by WITH CHECK alone — this shape permitted
        // a cross-tenant INSERT while every flag said "policed".
        $writeHole = PostgresRowLevelSecurityIsolation::policedTableViolations([
            ['policies' => json_encode(
                [['qual' => $canonical, 'check' => 'true', 'permissive' => true]],
                \JSON_THROW_ON_ERROR,
            )] + $safe,
        ]);
        self::assertCount(1, $writeHole);
        self::assertStringContainsString('WITH CHECK', $writeHole[0]);

        // An OR escape hatch on a SECOND custom GUC. Setting a custom GUC needs no privilege, so the
        // unprivileged runtime role flips it and reads every tenant — and the expression does mention
        // twes.tenant_id, which is why a substring test passed it.
        $escapeHatch = $canonical . " OR (current_setting('twes.support_mode'::text, true) = 'on'::text)";
        $hatched = PostgresRowLevelSecurityIsolation::policedTableViolations([
            ['policies' => json_encode(
                [['qual' => $escapeHatch, 'check' => $canonical, 'permissive' => true]],
                \JSON_THROW_ON_ERROR,
            )] + $safe,
        ]);
        self::assertCount(1, $hatched);
        self::assertStringContainsString('not the canonical tenant predicate', $hatched[0]);

        // `USING (true)` outright.
        self::assertCount(1, PostgresRowLevelSecurityIsolation::policedTableViolations([
            ['policies' => json_encode(
                [['qual' => 'true', 'check' => $canonical, 'permissive' => true]],
                \JSON_THROW_ON_ERROR,
            )] + $safe,
        ]));

        // A policy constraining NEITHER half.
        $vacuous = PostgresRowLevelSecurityIsolation::policedTableViolations([
            ['policies' => json_encode(
                [['qual' => null, 'check' => null, 'permissive' => true]],
                \JSON_THROW_ON_ERROR,
            )] + $safe,
        ]);
        self::assertCount(1, $vacuous);
        self::assertStringContainsString('constrains neither', $vacuous[0]);

        // ---- and the shapes that must be ACCEPTED, or the check is unusable --------------------------
        // A per-command PAIR: FOR SELECT carries only USING, FOR INSERT only WITH CHECK. Reading one half
        // and demanding it be non-null falsely REFUSED this correct configuration.
        self::assertSame([], PostgresRowLevelSecurityIsolation::policedTableViolations([
            ['policies' => json_encode([
                ['qual' => $canonical, 'check' => null, 'permissive' => true],
                ['qual' => null, 'check' => $canonical, 'permissive' => true],
            ], \JSON_THROW_ON_ERROR)] + $safe,
        ]), 'A per-command policy pair is correct and must not be refused.');

        // A RESTRICTIVE policy is ANDed, so an unscoped one only narrows access and cannot be a bypass.
        self::assertSame([], PostgresRowLevelSecurityIsolation::policedTableViolations([
            ['policies' => json_encode([
                ['qual' => $canonical, 'check' => $canonical, 'permissive' => true],
                ['qual' => 'true', 'check' => null, 'permissive' => false],
            ], \JSON_THROW_ON_ERROR)] + $safe,
        ]), 'A RESTRICTIVE policy narrows access; it is not a hole.');

        // A different tenant column is legitimate — policySqlFor() takes one — so the column name is the
        // only degree of freedom the comparison allows.
        self::assertSame([], PostgresRowLevelSecurityIsolation::policedTableViolations([
            ['policies' => json_encode([[
                'qual' => PostgresRowLevelSecurityIsolation::canonicalPolicyExpression('tenant_id'),
                'check' => PostgresRowLevelSecurityIsolation::canonicalPolicyExpression('tenant_id'),
                'permissive' => true,
            ]], \JSON_THROW_ON_ERROR)] + $safe,
        ]));

        // Every problem reported, not just the first.
        self::assertCount(4, PostgresRowLevelSecurityIsolation::policedTableViolations([
            ['owner_reachable' => 't', 'can_truncate' => 't', 'forced' => 'f', 'policies' => json_encode(
                [['qual' => 'true', 'check' => $canonical, 'permissive' => true]],
                \JSON_THROW_ON_ERROR,
            )] + $safe,
        ]));
    }

    /**
     * `TRUNCATE` removes EVERY tenant's rows, not just the bound tenant's.
     *
     * The earlier version of this test counted rows while still bound to tenant A, so a policy-scoped
     * `DELETE` produced an identical observation and the assertion could not tell them apart — it would
     * have passed unchanged on the day PostgreSQL made `TRUNCATE` RLS-scoped, which is exactly when it
     * should start failing. The count is now taken with RLS off, which is the only way to see B's row go.
     */
    public function testTruncateRemovesEveryTenantsRowsWhichIsWhyTheRoleMustNotHoldIt(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        // Run from the OWNER, because the runtime role no longer holds TRUNCATE — which is the conclusion
        // this test exists to justify, and is asserted directly by
        // testTheRuntimeRoleCannotDisableOrTruncateThePolicedTable. What is demonstrated here is *why* that
        // REVOKE matters: a bound session, scoped to tenant A by a policy that is present and forced, still
        // erases tenant B.
        $this->owner->beginTransaction();
        $isolation->bind($this->owner, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_A),
        ));
        $this->owner->exec('TRUNCATE ' . self::TABLE);
        $this->owner->commit();

        // With the policy off, so other tenants' rows are visible if any survived.
        $this->owner->exec('ALTER TABLE ' . self::TABLE . ' DISABLE ROW LEVEL SECURITY');

        try {
            $statement = $this->owner->query('SELECT count(*) FROM ' . self::TABLE);
            $total = false === $statement ? null : $statement->fetchColumn();
        } finally {
            $this->owner->exec('ALTER TABLE ' . self::TABLE . ' ENABLE ROW LEVEL SECURITY');
        }

        self::assertSame(
            0,
            (int) $total,
            "TRUNCATE must be shown to remove tenant B's row too. If this is 1, TRUNCATE became "
            . 'RLS-scoped and the REVOKE TRUNCATE requirement can be revisited.',
        );
    }

    /**
     * The SEVENTH bypass class: tenant data MATERIALISED at SESSION lifetime, which outlives the binding.
     *
     * Every guard in this class is transaction-shaped, because `bind()` is: `set_config(..., true)` is undone
     * on COMMIT, which is exactly what stops a binding leaking to whoever gets the connection next. Two things
     * PostgreSQL offers are **not** transaction-shaped, and both copy rows out from under the policy while it
     * is correctly in force:
     *
     *  - a **TEMPORARY TABLE**, which lives until the session ends and carries no row-level security of its own
     *    (it is not in the policed hierarchy, so no arm of the table check can ever see it);
     *  - a **`DECLARE … CURSOR WITH HOLD`**, which PostgreSQL materialises at COMMIT precisely so it can be
     *    read afterwards — and `pg_cursors` makes it discoverable to whoever holds the connection next.
     *
     * Both are available to the restricted runtime role at no privilege, and both are what an innocent
     * reporting job or a batch import writes. The demonstration below reads tenant A's rows while bound to
     * tenant B with **every other guard reporting clean**, which is why this needed a guard of its own rather
     * than a note.
     */
    public function testSessionLifetimeMaterialisedTenantDataIsRefusedAndDiscardable(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();
        $temporary = 'tenant_leak_tmp';
        $cursor = 'tenant_leak_cur';

        try {
            // Bound to tenant A, and correctly scoped: the SELECT below sees only A's rows.
            $this->connection->beginTransaction();
            $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
                TenantId::fromString(self::TENANT_A),
            ));
            $this->connection->exec(
                'CREATE TEMPORARY TABLE ' . $temporary . ' AS SELECT * FROM ' . self::TABLE,
            );
            $this->connection->exec(
                'DECLARE ' . $cursor . ' CURSOR WITH HOLD FOR SELECT label FROM ' . self::TABLE,
            );
            $this->connection->commit();

            // EVERY GUARD THAT PREDATED THIS CLASS, individually, on the same connection — all clean, which is
            // what made the leak below invisible when it was found. Note `assertConnectionCannotBypassPolicies()`
            // is deliberately NOT called here any more: round 12 composed the session-lifetime check into it, so
            // it now REFUSES this state, and calling it here would assert the very gap that has been closed.
            // The composite is asserted to refuse at the end of this test instead.
            PostgresRowLevelSecurityIsolation::assertPolicedTablesAreBeyondThisRolesReach($this->connection);
            PostgresRowLevelSecurityIsolation::assertNoRlsExemptObjectIsReadable($this->connection);
            $isolation->assertNoTenantPinnedOnTheConnection($this->connection);

            // THE LEAK, now bound to tenant B.
            $this->connection->beginTransaction();
            $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
                TenantId::fromString(self::TENANT_B),
            ));

            $policed = $this->connection->query('SELECT count(*) FROM ' . self::TABLE);
            self::assertNotFalse($policed);
            self::assertSame('1', (string) $policed->fetchColumn(), 'tenant B has exactly one row of its own');

            $copied = $this->connection->query('SELECT count(*) FROM ' . $temporary);
            self::assertNotFalse($copied);
            self::assertSame(
                '2',
                (string) $copied->fetchColumn(),
                "THE LEAK: a temporary table carries no policy, so tenant A's two rows are readable by "
                . 'tenant B for the rest of the session.',
            );

            $held = $this->connection->query('FETCH ALL FROM ' . $cursor);
            self::assertNotFalse($held);
            self::assertSame(
                ['a-one', 'a-two'],
                $held->fetchAll(\PDO::FETCH_COLUMN),
                'THE LEAK: a WITH HOLD cursor is materialised at COMMIT and read afterwards, under whatever '
                . 'tenant is bound then.',
            );

            $this->connection->commit();

            // Which the new guard refuses, naming both.
            $caught = null;

            try {
                PostgresRowLevelSecurityIsolation::assertNoSessionLifetimeDataIsMaterialised($this->connection);
            } catch (\RuntimeException $exception) {
                $caught = $exception;
            }

            self::assertNotNull($caught, 'A connection carrying session-lifetime tenant data must be refused.');
            self::assertStringContainsString($temporary, $caught->getMessage());
            self::assertStringContainsString($cursor, $caught->getMessage());

            // AND THE COMPOSITE ACQUISITION CHECK REFUSES IT TOO. This is the closure round 12 asked for: the
            // guard existed and was reachable only from its own test, so a pool wiring had two entry points to
            // remember instead of one. "A check nobody calls is not a check" — this project's own fifth-path
            // test asserts the identical property, and the seventh class shipped without it.
            $composite = null;

            try {
                $isolation->assertConnectionCannotBypassPolicies($this->connection);
            } catch (\RuntimeException $exception) {
                $composite = $exception;
            }

            self::assertNotNull(
                $composite,
                'The composite acquisition check must refuse a connection carrying session-lifetime data. If '
                . 'this passes, the call was removed from assertConnectionCannotBypassPolicies() and the '
                . 'direct assertion below cannot see that.',
            );
            self::assertStringContainsString($temporary, $composite->getMessage());

            // And discarding really removes both, so the guard is satisfiable rather than a dead end. This is
            // the release-time half: a connection returned to the pool must go back with nothing on it.
            PostgresRowLevelSecurityIsolation::discardSessionState($this->connection);
            PostgresRowLevelSecurityIsolation::assertNoSessionLifetimeDataIsMaterialised($this->connection);

            $gone = $this->connection->query(
                "SELECT count(*) FROM pg_cursors WHERE name = '" . $cursor . "'",
            );
            self::assertNotFalse($gone);
            self::assertSame('0', (string) $gone->fetchColumn(), 'DISCARD ALL closed the held cursor');
        } finally {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            $this->connection->exec('DISCARD ALL');
        }
    }

    /**
     * `discardSessionState()` CLEARS an open transaction rather than refusing — the direction was backwards.
     *
     * The first version threw inside a transaction, reasoning that `DISCARD ALL` raises 25001 there and an
     * explicit refusal beats an obscure failure. Round 12 refuted it: a connection is returned to the pool most
     * often on an EXCEPTION path, where a transaction is still open — so the one state the method refused was
     * the state it would most often be called in, and the dirtiest connection went back with the temp table,
     * the held cursor and the binding still on it. For a cleanup routine, fail-closed means "clear it anyway".
     *
     * Asserted with real dirt on the connection, not just an open transaction: a temp table, a held cursor and
     * a bound tenant. Asserting only that no exception is thrown would pass against a method that returned
     * early.
     */
    public function testDiscardingSessionStateClearsAnOpenTransactionRatherThanRefusing(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        $this->connection->beginTransaction();
        $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_A),
        ));
        $this->connection->exec('CREATE TEMPORARY TABLE discard_probe (id integer)');
        $this->connection->exec('DECLARE discard_cur CURSOR WITH HOLD FOR SELECT 1');

        // Still INSIDE the transaction, which is the state the old version refused.
        self::assertTrue($this->connection->inTransaction());

        PostgresRowLevelSecurityIsolation::discardSessionState($this->connection);

        self::assertFalse($this->connection->inTransaction(), 'the transaction must be rolled back');

        // And the connection must actually be clean, which is the point of calling it at all.
        PostgresRowLevelSecurityIsolation::assertNoSessionLifetimeDataIsMaterialised($this->connection);
        $isolation->assertNoTenantPinnedOnTheConnection($this->connection);
    }

    /**
     * THE EIGHTH CARRIER: a large object is readable under any binding, and DISCARD ALL cannot clear it.
     *
     * `pg_largeobject` is a system catalogue that cannot carry row-level security at any privilege level.
     * `lo_from_bytea`/`lo_get` need nothing the restricted runtime role lacks, and the default ACL is
     * owner-only — which, because every request connects as the SAME role, means every tenant's blob is
     * readable under every binding. A billing product generating invoice PDFs is the canonical use.
     *
     * The leak is demonstrated before the guard is asked about it, and the residue is removed inside the same
     * transaction so the suite leaves no permanent object behind.
     */
    public function testALargeObjectIsReadableUnderAnyBindingAndIsRefused(): void
    {
        $isolation = new PostgresRowLevelSecurityIsolation();

        // Clean slate: another test or a reviewer's probe must not decide this case.
        PostgresRowLevelSecurityIsolation::assertNoLargeObjectIsReachable($this->connection);

        $this->connection->beginTransaction();

        try {
            $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
                TenantId::fromString(self::TENANT_A),
            ));

            $created = $this->connection->query(
                "SELECT lo_from_bytea(0, 'tenant-A invoice PDF bytes') AS oid",
            );
            self::assertNotFalse($created);
            $oid = (string) $created->fetchColumn();

            // THE LEAK: the same connection, a different tenant, and the bytes come back.
            $this->connection->exec('SAVEPOINT rebind');
            $read = $this->connection->query(
                "SELECT convert_from(lo_get(" . $oid . "), 'UTF8') AS bytes",
            );
            self::assertNotFalse($read);
            self::assertSame(
                'tenant-A invoice PDF bytes',
                (string) $read->fetchColumn(),
                'A large object carries no policy, so its bytes are readable whatever tenant is bound.',
            );

            // And row-level security is not even possible on the catalogue that holds it.
            $rls = $this->connection->query(
                "SELECT relrowsecurity FROM pg_class WHERE relname = 'pg_largeobject'",
            );
            self::assertNotFalse($rls);
            self::assertNotContains(
                $rls->fetchColumn(),
                [true, 't', '1'],
                'pg_largeobject cannot carry RLS, which is why the rule is ZERO large objects.',
            );

            $caught = null;

            try {
                PostgresRowLevelSecurityIsolation::assertNoLargeObjectIsReachable($this->connection);
            } catch (\RuntimeException $exception) {
                $caught = $exception;
            }

            self::assertNotNull($caught, 'A reachable large object must be refused.');
            self::assertStringContainsString($oid, $caught->getMessage());
            self::assertStringContainsString('cannot carry row-level security', $caught->getMessage());
        } finally {
            // Rolled back rather than dropped: a large object created in a transaction disappears with it, so
            // this leaves the database exactly as it was even if an assertion above failed.
            $this->connection->rollBack();
        }

        PostgresRowLevelSecurityIsolation::assertNoLargeObjectIsReachable($this->connection);
    }

    /**
     * The TEMPORARY capability is refused for a role that lacks it, and its absence is what removes shadowing.
     *
     * Both directions, using roles this fixture already provisions: the runtime role HOLDS `TEMPORARY` because
     * the column-fidelity suite needs a scratch table, so it must be refused; `twes_bypass` is granted
     * `CONNECT` and not `TEMPORARY`, so it must be accepted. Without the accepting arm this would be a check
     * that cannot pass, which is a check somebody disables.
     */
    public function testTheTemporaryCapabilityIsRefusedWhenReachableAndAcceptedWhenNot(): void
    {
        $caught = null;

        try {
            PostgresRowLevelSecurityIsolation::assertConnectionCannotCreateTemporaryObjects($this->connection);
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertNotNull(
            $caught,
            'The runtime role holds TEMPORARY in this fixture, so the guard must refuse it. If this passes, '
            . 'either the grant was removed from provision-test-database.sh or the guard reads nothing.',
        );
        self::assertStringContainsString('pg_temp PRECEDES public', $caught->getMessage());

        // The ACCEPTING arm, on a role granted CONNECT but never TEMPORARY.
        $withoutTemp = self::connectAs('TWES_TEST_DB_BYPASS_USER', 'TWES_TEST_DB_BYPASS_PASSWORD');
        PostgresRowLevelSecurityIsolation::assertConnectionCannotCreateTemporaryObjects($withoutTemp);

        self::assertTrue(true, 'A role without TEMPORARY is accepted, so the guard is satisfiable.');
    }

    /**
     * The NULL-`datacl` arm, which is the DANGEROUS default and was untested until a mutant survived.
     *
     * A NULL `datacl` means "PostgreSQL's defaults apply", and that default grants `TEMPORARY` **and**
     * `CONNECT` to `PUBLIC`. Reading NULL as "no grants" therefore certifies every untouched database as safe —
     * the exact inversion the sibling arm for a function's `EXECUTE` exists to prevent.
     *
     * This fixture could not catch it: `provision-test-database.sh` REVOKEs from PUBLIC and then GRANTs, which
     * MATERIALISES `datacl`, so the explicit grant is found whichever way NULL is read. A mutant flipping the
     * default-grants arm to `false` passed the whole suite. [Verified on this cluster: `twes_in_test.datacl` is
     * `{twes_owner=CTc/...,twes=Tc/...}` while `postgres.datacl` is **NULL**.]
     *
     * So the case connects to a database whose ACL is genuinely untouched. That also exercises the scope
     * boundary `assertPolicedTablesAreBeyondThisRolesReach()` documents: PUBLIC retains `CONNECT` on other
     * databases, so the runtime role really can reach one — which is why that boundary is documented rather
     * than asserted, and why this test can exist at all.
     */
    public function testTheTemporaryGuardTreatsANullDatabaseAclAsTheDangerousDefault(): void
    {
        $user = getenv('TWES_TEST_DB_USER');
        $password = getenv('TWES_TEST_DB_PASSWORD');
        $host = getenv('TWES_TEST_DB_HOST') ?: '127.0.0.1';

        if (!\is_string($user) || !\is_string($password)) {
            self::markTestSkipped('No runtime credentials configured.');
        }

        try {
            $untouched = new \PDO(
                \sprintf('pgsql:host=%s;dbname=postgres', $host),
                $user,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\PDOException $exception) {
            self::markTestSkipped(
                'No database with an untouched ACL is reachable, so the NULL-datacl arm cannot be exercised '
                . 'here: ' . $exception->getMessage(),
            );
        }

        // The precondition: this database's ACL really is NULL, or the case proves nothing beyond the one above.
        $acl = $untouched->query(
            'SELECT datacl IS NULL AS untouched FROM pg_database WHERE datname = current_database()',
        );
        self::assertNotFalse($acl);
        self::assertContains(
            $acl->fetchColumn(),
            [true, 't', '1'],
            'This test needs a database whose datacl is NULL. If it is not, pick another.',
        );

        $caught = null;

        try {
            PostgresRowLevelSecurityIsolation::assertConnectionCannotCreateTemporaryObjects($untouched);
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertNotNull(
            $caught,
            'A NULL datacl grants TEMPORARY to PUBLIC, so it must be REFUSED. Reading NULL as "no grants" '
            . 'certifies every untouched database as safe.',
        );
        self::assertStringContainsString('NULL datacl', $caught->getMessage());
    }

    /**
     * A `proconfig` function that PINS the tenant setting is refused — independent of `SECURITY DEFINER`.
     *
     * PostgreSQL saves and restores GUCs around any call whose `proconfig` is non-null, and that mechanism has
     * nothing to do with `prosecdef`. So a function declared `SET "twes.tenant_id" = '<other tenant>'` scopes
     * every policy inside it to that tenant for the duration of the call, while the caller remains bound to its
     * own — and `prosecdef` is false, so the SECURITY DEFINER query never saw it, and it is not a relation, so
     * the view query never saw it either.
     *
     * VERIFIED as a real leak before this was written, not inferred: a connection bound to tenant B read tenant
     * A's row through such a function while its direct read of the same table correctly returned 0.
     *
     * **The threat actor is a superuser or somebody holding `GRANT SET ON PARAMETER`, NOT the runtime role.**
     * `twes_owner` cannot create one — `permission denied to set parameter "twes.tenant_id"`, and
     * `has_parameter_privilege('twes_owner', 'twes.tenant_id', 'SET')` is false. That is why this needs a
     * superuser connection to arrange and skips without one. It is still worth detecting for exactly the reason
     * the leaking-view case is: once such a function exists, any role holding EXECUTE calls it forever, so it is
     * a persistent delegated bypass rather than a one-off act by a privileged role.
     */
    public function testAFunctionPinningTheTenantSettingViaProconfigIsRefused(): void
    {
        $granter = self::superuserConnection();

        if (null === $granter) {
            self::markTestSkipped(
                'No superuser connection available. Only a superuser (or a role granted SET on the parameter) '
                . 'can create a proconfig function, which is itself the reason this case exists.',
            );
        }

        $runtimeRole = getenv('TWES_TEST_DB_USER');
        self::assertIsString($runtimeRole);

        $function = self::TABLE . '_proconfig';

        $granter->exec('DROP FUNCTION IF EXISTS ' . $function . '()');
        $granter->exec(
            'CREATE FUNCTION ' . $function . '() RETURNS bigint LANGUAGE sql '
            . 'SET "' . PostgresRowLevelSecurityIsolation::TENANT_SETTING . '" = \'' . self::TENANT_A . '\' '
            . 'AS \'SELECT count(*) FROM ' . self::TABLE . '\'',
        );

        try {
            $granter->exec('GRANT EXECUTE ON FUNCTION ' . $function . '() TO ' . $runtimeRole);

            // The preconditions: NOT security definer, and proconfig really carries the setting.
            $shape = $this->connection->query(
                'SELECT prosecdef, coalesce(array_to_string(proconfig, \', \'), \'\') AS cfg '
                . 'FROM pg_proc WHERE proname = \'' . $function . '\'',
            );
            self::assertNotFalse($shape);
            /** @var array{prosecdef: bool|string, cfg: string} $flags */
            $flags = $shape->fetch(\PDO::FETCH_ASSOC);
            self::assertNotContains($flags['prosecdef'], [true, 't', '1'], 'deliberately NOT security definer');
            self::assertStringContainsString(
                PostgresRowLevelSecurityIsolation::TENANT_SETTING,
                $flags['cfg'],
                'proconfig must pin the tenant setting, or this case is about nothing',
            );

            $caught = null;

            try {
                PostgresRowLevelSecurityIsolation::assertNoRlsExemptObjectIsReadable($this->connection);
            } catch (\RuntimeException $exception) {
                $caught = $exception;
            }

            self::assertNotNull($caught, 'A proconfig function pinning the tenant setting must be refused.');
            self::assertStringContainsString($function, $caught->getMessage());
            // And the REASON must be the proconfig one. Calling it "SECURITY DEFINER" would be false and would
            // send a reader to check an ownership chain that has nothing to do with the leak.
            self::assertStringContainsString('pins the tenant setting', $caught->getMessage());
            self::assertStringContainsString('which it is not', $caught->getMessage());

            // AND WITH THE OWNER SET TO THE CONNECTION'S OWN ROLE, which is the case the owner filter would
            // otherwise swallow. The SECURITY DEFINER arm legitimately excludes a function whose owner this
            // connection can already become — calling it is then no escalation — but a `proconfig` function
            // does not borrow the owner's rights at all, it rewrites the tenant binding, so ownership is
            // irrelevant to its danger. A mutant re-applying the owner filter to this arm survived until this
            // assertion existed, because the function above is owned by the superuser that created it and is
            // therefore never assumable.
            $granter->exec('ALTER FUNCTION ' . $function . '() OWNER TO ' . $runtimeRole);

            $assumable = $this->connection->query(
                'SELECT pg_has_role(current_user, proowner, \'SET\') AS owner_assumable '
                . 'FROM pg_proc WHERE proname = \'' . $function . '\'',
            );
            self::assertNotFalse($assumable);
            self::assertContains(
                $assumable->fetchColumn(),
                [true, 't', '1'],
                'The owner must now be assumable, or this half of the case is the same as the half above.',
            );

            $stillCaught = null;

            try {
                PostgresRowLevelSecurityIsolation::assertNoRlsExemptObjectIsReadable($this->connection);
            } catch (\RuntimeException $exception) {
                $stillCaught = $exception;
            }

            self::assertNotNull(
                $stillCaught,
                'A proconfig function must be refused even when its owner IS assumable: it does not borrow '
                . 'the owner\'s rights, it rewrites the binding.',
            );
            self::assertStringContainsString($function, $stillCaught->getMessage());
        } finally {
            // Ownership may have moved to the runtime role, which the superuser can still drop.
            $granter->exec('DROP FUNCTION IF EXISTS ' . $function . '()');
        }
    }

    /**
     * A view owned by the QUERYING role is not reported, because it is genuinely safe.
     *
     * "Evaluate row-level security as the owner" and "evaluate it as the caller" are the same evaluation when
     * the owner IS the caller, so a `security_invoker`-less view owned by the connection's own role leaks
     * nothing. Reporting it would be a false positive, and a check that fires on a safe shape is precisely the
     * argument this class makes elsewhere for NOT asserting cross-database `CONNECT` — a check somebody
     * disables is worse than a documented boundary.
     *
     * Latent in this project today: the runtime role holds `CREATE` on no schema, so it cannot create such a
     * view. Tested as a pure classification for that reason, and tested at all because the arm is otherwise a
     * rule nothing exercises — the defect CLAUDE.md § Gotchas records for `PERMISSIVE_FOR_FONT_ASSETS`.
     */
    public function testAViewOwnedByTheQueryingRoleIsNotReported(): void
    {
        $base = [
            'object' => 'public.mine',
            'kind' => 'v',
            'owner' => 'twes',
            'owner_exempt' => 'f',
            'security_invoker' => 'f',
        ];

        // Owned by somebody else and not security_invoker: reported.
        self::assertCount(
            1,
            PostgresRowLevelSecurityIsolation::rlsExemptObjectViolations([
                [...$base, 'owner' => 'twes_owner', 'owned_by_caller' => 'f'],
            ]),
        );

        // Same shape, owned by the caller: NOT reported.
        self::assertSame(
            [],
            PostgresRowLevelSecurityIsolation::rlsExemptObjectViolations([
                [...$base, 'owned_by_caller' => 't'],
            ]),
        );

        // And ownership does NOT rescue a MATERIALISED view: a matview carries no row-level security at all,
        // so who owns it is irrelevant. Asserted because the new arm is placed after the kind check and a
        // later edit could reorder them.
        self::assertCount(
            1,
            PostgresRowLevelSecurityIsolation::rlsExemptObjectViolations([
                [...$base, 'kind' => 'm', 'owned_by_caller' => 't'],
            ]),
        );
    }

    /**
     * A MIXED-CASE quoted GUC name in `proconfig` is caught — the defeat of round 12's own closure.
     *
     * `SET "TWES.TENANT_ID" = '<tenant>'` stores `proconfig = {TWES.TENANT_ID=...}` **verbatim**, because
     * PostgreSQL normalises a custom GUC's name only when a placeholder for it already exists in that backend.
     * At call time the GUC resolves CASE-INSENSITIVELY, so the function pins the parameter the policy reads —
     * while a case-sensitive `LIKE 'twes.tenant_id=%'` misses it entirely. One keystroke defeated the fix.
     *
     * The attacker needs no extra privilege for the quoted spelling: `pg_parameter_acl` lowercases its keys, so
     * a `GRANT SET ON PARAMETER twes.tenant_id` covers `"TWES.TENANT_ID"` too.
     */
    public function testAMixedCaseQuotedTenantSettingInProconfigIsCaught(): void
    {
        $granter = self::superuserConnection();

        if (null === $granter) {
            self::markTestSkipped('No superuser connection available to set a parameter in a function.');
        }

        $runtimeRole = getenv('TWES_TEST_DB_USER');
        self::assertIsString($runtimeRole);

        $function = self::TABLE . '_proconfig_mixedcase';
        $setting = strtoupper(PostgresRowLevelSecurityIsolation::TENANT_SETTING);

        $granter->exec('DROP FUNCTION IF EXISTS ' . $function . '()');
        $granter->exec(
            'CREATE FUNCTION ' . $function . '() RETURNS bigint LANGUAGE sql '
            . 'SET "' . $setting . '" = \'' . self::TENANT_A . '\' '
            . 'AS \'SELECT count(*) FROM ' . self::TABLE . '\'',
        );

        try {
            $granter->exec('GRANT EXECUTE ON FUNCTION ' . $function . '() TO ' . $runtimeRole);

            // The precondition: PostgreSQL really stored the name UPPERCASE, so a case-sensitive match fails.
            $stored = $this->connection->query(
                'SELECT array_to_string(proconfig, \', \') AS cfg FROM pg_proc WHERE proname = \''
                . $function . '\'',
            );
            self::assertNotFalse($stored);
            $cfg = (string) $stored->fetchColumn();
            self::assertStringContainsString($setting, $cfg, 'stored verbatim, in upper case');
            self::assertStringNotContainsString(
                PostgresRowLevelSecurityIsolation::TENANT_SETTING . '=',
                $cfg,
                'and NOT in the lower-case spelling a case-sensitive match looks for',
            );

            $caught = null;

            try {
                PostgresRowLevelSecurityIsolation::assertNoRlsExemptObjectIsReadable($this->connection);
            } catch (\RuntimeException $exception) {
                $caught = $exception;
            }

            self::assertNotNull($caught, 'A mixed-case quoted GUC name must not defeat the check.');
            self::assertStringContainsString($function, $caught->getMessage());
            self::assertStringContainsString('pins the tenant setting', $caught->getMessage());
        } finally {
            $granter->exec('DROP FUNCTION IF EXISTS ' . $function . '()');
        }
    }

    /**
     * THE NINTH CARRIER: a TRIGGER function runs without EXECUTE, so the EXECUTE filter excluded exactly the
     * functions that fire anyway.
     *
     * PostgreSQL checks `EXECUTE` against the trigger's CREATOR at `CREATE TRIGGER` and performs **no** ACL
     * check when the trigger fires. So a trigger function with `EXECUTE` revoked from PUBLIC and no grant to the
     * runtime role runs on every INSERT/UPDATE/DELETE that role issues — while
     * `privilegeIsReachableSql('p.proacl','EXECUTE',true)` is false and the row is dropped from the result set.
     * That neutralised BOTH the `prosecdef` arm and round 12's new `proconfig` arm at once.
     *
     * [Verified independently: `has_function_privilege('twes', fn, 'EXECUTE')` is false while the trigger fires
     * under `current_user = twes`.]
     */
    public function testATriggerFunctionIsInspectedEvenWithoutExecutePrivilege(): void
    {
        $granter = self::superuserConnection();

        if (null === $granter) {
            self::markTestSkipped('No superuser connection available to own a SECURITY DEFINER function.');
        }

        $runtimeRole = getenv('TWES_TEST_DB_USER');
        self::assertIsString($runtimeRole);

        $function = self::TABLE . '_trigfn';
        $table = self::TABLE . '_trigtarget';

        $granter->exec('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
        $granter->exec('DROP FUNCTION IF EXISTS ' . $function . '() CASCADE');
        $granter->exec('CREATE TABLE ' . $table . ' (id integer)');
        // SECURITY DEFINER and owned by the superuser: the dangerous shape. What makes this case distinct is
        // that EXECUTE is REVOKED, so the pre-fix filter never saw it.
        $granter->exec(
            'CREATE FUNCTION ' . $function . '() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER '
            . 'AS \'BEGIN RETURN NEW; END\'',
        );

        try {
            $granter->exec('REVOKE EXECUTE ON FUNCTION ' . $function . '() FROM PUBLIC');
            $granter->exec(
                'CREATE TRIGGER tg BEFORE INSERT ON ' . $table
                . ' FOR EACH ROW EXECUTE FUNCTION ' . $function . '()',
            );
            $granter->exec('GRANT INSERT ON ' . $table . ' TO ' . $runtimeRole);

            // The precondition, and the whole point: NOT executable, yet it fires.
            $priv = $this->connection->query(
                'SELECT has_function_privilege(current_user, \'' . $function . '()\', \'EXECUTE\') AS can',
            );
            self::assertNotFalse($priv);
            self::assertNotContains(
                $priv->fetchColumn(),
                [true, 't', '1'],
                'EXECUTE must be revoked, or this case is the same as the ordinary one',
            );

            $caught = null;

            try {
                PostgresRowLevelSecurityIsolation::assertNoRlsExemptObjectIsReadable($this->connection);
            } catch (\RuntimeException $exception) {
                $caught = $exception;
            }

            self::assertNotNull(
                $caught,
                'A trigger function must be inspected regardless of EXECUTE: the trigger fires without it.',
            );
            self::assertStringContainsString($function, $caught->getMessage());
        } finally {
            $granter->exec('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
            $granter->exec('DROP FUNCTION IF EXISTS ' . $function . '() CASCADE');
        }
    }

    // ------------------------------------------------------------------ fixture

    /** The restricted runtime role — the one every isolation assertion is made against. */
    private static function connect(string $extraDsn = ''): \PDO
    {
        return self::connectAs('TWES_TEST_DB_USER', 'TWES_TEST_DB_PASSWORD', $extraDsn);
    }

    /**
     * A superuser connection, or null.
     *
     * Only used to grant and revoke a predefined role inside a test — the owner role cannot, deliberately.
     * Returns null rather than skipping the whole suite, because every other test must run without it.
     */
    private static function superuserConnection(): ?\PDO
    {
        $dsn = getenv('TWES_TEST_DSN');
        $user = getenv('TWES_TEST_DB_SUPERUSER');
        $password = getenv('TWES_TEST_DB_SUPERUSER_PASSWORD');

        if (!\is_string($dsn) || !\is_string($user) || '' === $user) {
            return null;
        }

        try {
            return new \PDO($dsn, $user, \is_string($password) ? $password : null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException) {
            return null;
        }
    }

    /** The owning role, standing in for a migration. Must not be reachable from the runtime role. */
    private static function connectAsOwner(): \PDO
    {
        return self::connectAs('TWES_TEST_DB_OWNER_USER', 'TWES_TEST_DB_OWNER_PASSWORD');
    }

    /**
     * A connection as one of the four provisioned roles.
     *
     * Parameterised by env var name rather than taking credentials, so a test naming a role it needs gets
     * the same skip message as everything else when the database is not provisioned — see
     * scripts/dev/provision-test-database.sh, and CLAUDE.md § "Quality gate" for the variables.
     */
    private static function connectAs(string $userVariable, string $passwordVariable, string $extraDsn = ''): \PDO
    {
        $dsn = getenv('TWES_TEST_DSN');
        $user = getenv($userVariable);
        $password = getenv($passwordVariable);

        if (!\is_string($dsn) || !\is_string($user) || !\is_string($password)) {
            self::fail(\sprintf(
                'TWES_TEST_DSN, %s and %s must be set. Run scripts/dev/provision-test-database.sh as a '
                . 'superuser to create the roles the tenancy proof needs.',
                $userVariable,
                $passwordVariable,
            ));
        }

        try {
            return new \PDO($dsn . ('' === $extraDsn ? '' : ';' . $extraDsn), $user, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\PDOException $exception) {
            // FAIL, NEVER SKIP. See DatabaseRequirement for why, and for the two-cluster accident that
            // proved it: 62 skipped tests, exit 0, and the tenancy proof not run.
            self::fail(DatabaseRequirement::unreachable($exception));
        }
    }

    /**
     * Builds the table, its policy and its rows — as the OWNING role, exactly as a migration would.
     *
     * A probe table rather than a domain entity, because Wave 0 has no entities yet — it proves the
     * *mechanism*. Each later wave owes its own proof that its own tables carry the policy; that is a
     * completeness-reviewer obligation, not something this test covers for them.
     */
    private function createProbeTable(): void
    {
        $this->owner->exec('DROP TABLE IF EXISTS ' . self::TABLE);

        // PRIMARY KEY (company_id, id), not a bare `id`: referential-integrity and uniqueness checks run
        // with row security BYPASSED, so a single-column key on tenant-owned data is an existence oracle
        // for another tenant's rows. policySqlFor()'s docblock states that requirement, and a review
        // pointed out that this fixture — the canonical emitter's own witness — did not satisfy it.
        $this->owner->exec(
            'CREATE TABLE ' . self::TABLE . ' (
                company_id uuid NOT NULL,
                id         integer NOT NULL,
                label      text NOT NULL,
                PRIMARY KEY (company_id, id)
            )',
        );

        // ENABLE alone leaves the table's owner exempt. FORCE is what closes that, and every migration
        // enabling RLS must do both. From the canonical emitter, not hand-written: the test must exercise
        // the exact SQL that migrations will run, or it proves something no production table has.
        foreach (PostgresRowLevelSecurityIsolation::policySqlFor(self::TABLE) as $statement) {
            $this->owner->exec($statement);
        }

        // The runtime role gets DML and deliberately not TRUNCATE. Granted explicitly rather than relying
        // on the ALTER DEFAULT PRIVILEGES the provisioning script sets, so that a database provisioned
        // slightly differently still runs the suite it is supposed to run.
        $runtimeRole = getenv('TWES_TEST_DB_USER');

        if (\is_string($runtimeRole)) {
            $this->owner->exec(\sprintf(
                'GRANT SELECT, INSERT, UPDATE, DELETE ON %s TO %s',
                self::TABLE,
                $runtimeRole,
            ));
            $this->owner->exec('REVOKE TRUNCATE ON ' . self::TABLE . ' FROM ' . $runtimeRole);
        }

        // The second policed table. Deliberately minimal — it carries no rows and no test reads it. Its only
        // purpose is that the number of policed tables is greater than one, so a hardcoded count fails.
        $this->owner->exec('DROP TABLE IF EXISTS ' . self::SECOND_TABLE);
        $this->owner->exec(
            'CREATE TABLE ' . self::SECOND_TABLE . ' (
                company_id uuid NOT NULL,
                id         integer NOT NULL,
                PRIMARY KEY (company_id, id)
            )',
        );

        foreach (PostgresRowLevelSecurityIsolation::policySqlFor(self::SECOND_TABLE) as $statement) {
            $this->owner->exec($statement);
        }

        // Seeded with RLS off, since seeding spans both tenants by definition.
        $this->owner->exec('ALTER TABLE ' . self::TABLE . ' DISABLE ROW LEVEL SECURITY');
        $insert = $this->owner->prepare(
            'INSERT INTO ' . self::TABLE . ' (id, company_id, label) VALUES (?, ?, ?)',
        );
        $insert->execute([1, self::TENANT_A, 'a-one']);
        $insert->execute([2, self::TENANT_A, 'a-two']);
        $insert->execute([3, self::TENANT_B, 'b-one']);
        $this->owner->exec('ALTER TABLE ' . self::TABLE . ' ENABLE ROW LEVEL SECURITY');
    }

    /** @return list<string> */
    private function fetchAllLabels(): array
    {
        $statement = $this->connection->query('SELECT label FROM ' . self::TABLE . ' ORDER BY id');

        if (false === $statement) {
            self::fail('Query failed.');
        }

        /** @var list<string> $labels */
        $labels = $statement->fetchAll(\PDO::FETCH_COLUMN);

        return $labels;
    }
}

/**
 * A `PDOStatement` that reports a different value than the one written.
 *
 * Exists solely to drive `bind()`'s read-back mismatch branch, which I had recorded as unreachable. PDO's
 * `ATTR_STATEMENT_CLASS` substitutes this natively, so the statement still executes against a real
 * PostgreSQL connection — only what `fetchColumn()` reports is changed.
 *
 * The `protected` no-arg constructor is PDO's requirement for a substituted statement class.
 */
final class LyingStatement extends \PDOStatement
{
    public const string WRONG_VALUE = 'a-different-tenant';

    /**
     * Lies on the SECOND read only.
     *
     * `bind()` reads the setting twice: once BEFORE writing, to refuse a connection that already carries a
     * tenant, and once after, to verify the write took. Lying on the first makes `bind()` refuse for the
     * *other* reason and the read-back branch is never reached — which is what happened on the first attempt
     * at this test, and is a neat illustration of why "it threw" is not the same as "the branch ran".
     */
    private static int $reads = 0;

    protected function __construct() {}

    public static function reset(): void
    {
        self::$reads = 0;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        ++self::$reads;

        // First read: the pre-write check. Report "no tenant" so bind() proceeds to write.
        return 1 === self::$reads ? '' : self::WRONG_VALUE;
    }
}

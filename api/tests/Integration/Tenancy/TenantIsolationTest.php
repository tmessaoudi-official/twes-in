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
use PHPUnit\Framework\TestCase;
use Twes\Infrastructure\Tenancy\Exception\NoCurrentTenant;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;
use Twes\Infrastructure\Tenancy\TenantId;

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
            self::assertStringContainsString('never reference', $exception->getMessage());
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
     * The three ways a policed table can be reached around, as a pure function over catalogue rows.
     *
     * The live tests above prove the query and the wiring; this pins the classification itself, including
     * the `t`/`f` string spellings, which are the difference between a violation and a clean bill.
     */
    public function testThePolicedTableViolationPredicateClassifiesEveryReachableShape(): void
    {
        $safe = [
            'table' => 'public.invoices',
            'owner' => 'twes_owner',
            'owner_reachable' => 'f',
            'can_truncate' => 'f',
            'forced' => 't',
            'policies' => 1,
            'scoped_policies' => 1,
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

        // A policy that isolates NOTHING while both flags are set. `USING (true)` is indistinguishable from
        // a correct policy in pg_class, which is why the expression has to be read: round 5 got a clean
        // verdict on a table that was readable AND writable across tenants.
        $unscoped = PostgresRowLevelSecurityIsolation::policedTableViolations(
            [['policies' => 2, 'scoped_policies' => 1] + $safe],
        );
        self::assertCount(1, $unscoped);
        self::assertStringContainsString('never reference twes.tenant_id', $unscoped[0]);

        // Plural, because an operator reading "1 policies" learns the message was never exercised.
        $twoUnscoped = PostgresRowLevelSecurityIsolation::policedTableViolations(
            [['policies' => 3, 'scoped_policies' => 1] + $safe],
        );
        self::assertStringContainsString('2 policies', $twoUnscoped[0]);

        // A table with RLS enabled and NO policy denies everything, which is fail-closed, so it is not a
        // violation. Asserted so a future "policies must be >= 1" edit is a deliberate change of direction.
        self::assertSame([], PostgresRowLevelSecurityIsolation::policedTableViolations(
            [['policies' => 0, 'scoped_policies' => 0] + $safe],
        ));

        // Every problem reported, not just the first: an operator fixing one at a time needs to see all
        // four, and a `return` where a `[] =` belongs would hide three of them.
        self::assertCount(4, PostgresRowLevelSecurityIsolation::policedTableViolations(
            [['owner_reachable' => 't', 'can_truncate' => 't', 'forced' => 'f', 'policies' => 1, 'scoped_policies' => 0] + $safe],
        ));

        // And every table, not just the first row.
        self::assertCount(2, PostgresRowLevelSecurityIsolation::policedTableViolations([
            ['table' => 'public.a', 'owner_reachable' => 't'] + $safe,
            ['table' => 'public.b', 'can_truncate' => 't'] + $safe,
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

    // ------------------------------------------------------------------ fixture

    /** The restricted runtime role — the one every isolation assertion is made against. */
    private static function connect(string $extraDsn = ''): \PDO
    {
        return self::connectAs('TWES_TEST_DB_USER', 'TWES_TEST_DB_PASSWORD', $extraDsn);
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
            self::markTestSkipped(\sprintf(
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
            self::markTestSkipped('No PostgreSQL server reachable: ' . $exception->getMessage());
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

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
 */
#[CoversClass(PostgresRowLevelSecurityIsolation::class)]
final class TenantIsolationTest extends TestCase
{
    private const string TABLE = 'tenant_isolation_probe';

    private const string TENANT_A = '01926b3c-0000-7000-8000-00000000000a';
    private const string TENANT_B = '01926b3c-0000-7000-8000-00000000000b';

    private \PDO $connection;

    protected function setUp(): void
    {
        $this->connection = self::connect();
        $this->createProbeTable();
    }

    protected function tearDown(): void
    {
        // Guarded because PHPUnit runs tearDown even when setUp called markTestSkipped, and an
        // unguarded dereference turns nine intended skips into nine type errors that name the wrong
        // file — hiding the one message an operator needs ("no PostgreSQL server reachable").
        if (isset($this->connection)) {
            $this->connection->exec('DROP TABLE IF EXISTS ' . self::TABLE);
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
        $this->connection->exec('ALTER TABLE ' . self::TABLE . ' DISABLE ROW LEVEL SECURITY');

        try {
            $this->connection->beginTransaction();
            $leaked = $this->fetchAllLabels();
            $this->connection->rollBack();
        } finally {
            $this->connection->exec('ALTER TABLE ' . self::TABLE . ' ENABLE ROW LEVEL SECURITY');
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

        // Does not throw. A superuser or BYPASSRLS role would make every assertion above vacuous while
        // leaving the policies visibly in place, so this is checked rather than assumed.
        $isolation->assertConnectionCannotBypassPolicies($this->connection);

        $this->expectNotToPerformAssertions();
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

        $this->expectNotToPerformAssertions();
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

        $this->connection->beginTransaction();
        $isolation->bind($this->connection, InMemoryTenantContext::forTenant(
            TenantId::fromString(self::TENANT_A),
        ));
        $this->connection->exec('TRUNCATE ' . self::TABLE);
        $this->connection->commit();

        // With the policy off, so other tenants' rows are visible if any survived.
        $this->connection->exec('ALTER TABLE ' . self::TABLE . ' DISABLE ROW LEVEL SECURITY');

        try {
            $statement = $this->connection->query('SELECT count(*) FROM ' . self::TABLE);
            $total = false === $statement ? null : $statement->fetchColumn();
        } finally {
            $this->connection->exec('ALTER TABLE ' . self::TABLE . ' ENABLE ROW LEVEL SECURITY');
        }

        self::assertSame(
            0,
            (int) $total,
            "TRUNCATE must be shown to remove tenant B's row too. If this is 1, TRUNCATE became "
            . 'RLS-scoped and the REVOKE TRUNCATE requirement can be revisited.',
        );
    }

    // ------------------------------------------------------------------ fixture

    private static function connect(string $extraDsn = ''): \PDO
    {
        $dsn = getenv('TWES_TEST_DSN');
        $user = getenv('TWES_TEST_DB_USER');
        $password = getenv('TWES_TEST_DB_PASSWORD');

        if (!\is_string($dsn) || !\is_string($user) || !\is_string($password)) {
            self::markTestSkipped('TWES_TEST_DSN, TWES_TEST_DB_USER and TWES_TEST_DB_PASSWORD must be set.');
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
     * Builds the table, its policy and its rows.
     *
     * A probe table rather than a domain entity, because Wave 0 has no entities yet — it proves the
     * *mechanism*. Each later wave owes its own proof that its own tables carry the policy; that is a
     * completeness-reviewer obligation, not something this test covers for them.
     */
    private function createProbeTable(): void
    {
        $this->connection->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->connection->exec(
            'CREATE TABLE ' . self::TABLE . ' (
                id         integer PRIMARY KEY,
                company_id uuid NOT NULL,
                label      text NOT NULL
            )',
        );

        // ENABLE alone leaves the table's owner exempt, and the application connects as the owner.
        // FORCE is what closes that, and every migration enabling RLS must do both.
        // From the canonical emitter, not hand-written: the test must exercise the exact SQL that
        // migrations will run, or it proves something no production table has.
        foreach (PostgresRowLevelSecurityIsolation::policySqlFor(self::TABLE) as $statement) {
            $this->connection->exec($statement);
        }

        // Seeded with RLS off, since seeding spans both tenants by definition.
        $this->connection->exec('ALTER TABLE ' . self::TABLE . ' DISABLE ROW LEVEL SECURITY');
        $insert = $this->connection->prepare(
            'INSERT INTO ' . self::TABLE . ' (id, company_id, label) VALUES (?, ?, ?)',
        );
        $insert->execute([1, self::TENANT_A, 'a-one']);
        $insert->execute([2, self::TENANT_A, 'a-two']);
        $insert->execute([3, self::TENANT_B, 'b-one']);
        $this->connection->exec('ALTER TABLE ' . self::TABLE . ' ENABLE ROW LEVEL SECURITY');
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

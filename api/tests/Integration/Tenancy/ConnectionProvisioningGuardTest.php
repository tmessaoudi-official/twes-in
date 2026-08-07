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

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Twes\Infrastructure\Tenancy\Doctrine\ConnectionProvisioningGuardDriver;
use Twes\Infrastructure\Tenancy\Doctrine\ConnectionProvisioningGuardMiddleware;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;
use Twes\Tests\Integration\DatabaseRequirement;

/**
 * THE ACQUIRE HALF OF THE CONNECTION LIFECYCLE, and the CADENCE the developer ruled on.
 *
 * `TenantIsolationTest` proves `assertConnectionCannotBypassPolicies()` detects what it claims to. This proves the
 * WIRING and the CACHING: that acquiring a connection runs it without a caller remembering to, that a second
 * acquisition inside the TTL does NOT re-run it, and — the half that matters most — that a FAILED verification is
 * never cached as either outcome.
 *
 * **Why the caching needs its own tests rather than being an implementation detail:** it is the whole reason the
 * cadence ruling was a decision. The assertions cost ~10.8 ms [measured], almost every property they check is static
 * per (role, database), and caching a security check is the kind of optimisation that turns a control into a
 * decoration if the invalidation is wrong. So both directions are asserted: it must skip when it legitimately can,
 * and it must NOT skip when it cannot.
 */
#[CoversClass(ConnectionProvisioningGuardMiddleware::class)]
#[CoversClass(ConnectionProvisioningGuardDriver::class)]
final class ConnectionProvisioningGuardTest extends TestCase
{
    // A MIGRATED PROBE DATABASE, not the shared test one, and the reason is the composite check's own anti-vacuity
    // guard: it REFUSES a database with no row-level security enabled anywhere, because a pass there would prove
    // nothing. `twes_in_test` carries no policied tables of its own — `TenantIsolationTest` creates and drops its
    // fixtures — so the guard cannot be exercised against it at all. [Verified: `No table in this database has
    // row-level security enabled, so there is no isolation to be subject to`.] That refusal is correct and is why
    // this class needs a real migrated schema.
    use MigratedProbeDatabase;

    private const DATABASE = 'twes_provisioning_guard_probe';

    public static function setUpBeforeClass(): void
    {
        self::createMigratedProbeDatabase(self::DATABASE);

        // The runtime role needs DML in a freshly created database: `ALTER DEFAULT PRIVILEGES` entries are
        // PER-DATABASE catalogue rows, so the ones `provision-test-database.sh` created apply to `twes_in_test` and
        // to nothing else. Same reasoning, and the same fix, as `BehaviouralIsolationTest::grantRuntimeDml()`.
        // `connectionTo(...)` and not `superuserConnection()`: the latter connects to the SHARED test database, so the
        // grants landed on a `document` table that does not exist there. [Verified: `relation "document" does not
        // exist`.] The grants must be issued INSIDE the probe, which is what the trait's own accessor is for.
        $admin = self::connectionTo(self::DATABASE, self::ownerRole(), self::ownerPassword());

        foreach (['document', 'document_line', 'document_charge', 'document_number_sequence'] as $table) {
            $admin->exec(\sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON %s TO %s', $table, self::runtimeRole()));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropProbeDatabase(self::DATABASE);
    }

    /**
     * A REAL ACQUISITION RUNS THE CHECK, with no call site of its own.
     *
     * `DriverManager::getConnection()` is lazy, so the first query is what triggers `connect()` — which is precisely
     * why the guard belongs there rather than in a factory: nothing has to remember to ask.
     */
    public function testAcquiringAConnectionVerifiesProvisioning(): void
    {
        $pool = new ArrayAdapter();
        $connection = $this->guardedConnection($pool);

        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'), 'the connection works');

        // The observable consequence of the check having run and passed: the pool now holds the verification.
        self::assertNotEmpty($pool->getValues(), 'a passing verification is remembered');

        $connection->close();
    }

    /**
     * A ROLE THAT CAN BYPASS ROW-LEVEL SECURITY IS REFUSED AT ACQUISITION.
     *
     * The anti-vacuity half of every case below: without this, a guard that verified nothing would satisfy them all.
     * `twes_bypass` holds `BYPASSRLS`, so every policy is inert for it — which is the single worst credential the
     * application could be configured with, and exactly what obligation 2 exists to refuse.
     */
    public function testARoleThatCanBypassPoliciesIsRefusedAtAcquisition(): void
    {
        $connection = $this->guardedConnection(new ArrayAdapter(), connectAs: self::bypassCredentials());

        $this->expectException(\RuntimeException::class);
        $connection->fetchOne('SELECT 1');
    }

    /**
     * A VERIFICATION INSIDE THE TTL IS SERVED FROM THE CACHE — the cadence ruling, asserted.
     *
     * Proven the only way that distinguishes "skipped" from "faster": pre-seed the cache for a role the checker
     * genuinely REFUSES, then acquire as that role. Succeeding is only possible if the assertions never ran. A timing
     * comparison would be flaky and would not prove the branch.
     *
     * The key comes from `cacheKeyFor()` — the function the middleware itself uses — so this test cannot pass by
     * agreeing with a copy of the formula about the wrong key.
     */
    public function testAVerificationInsideTheTtlIsServedFromTheCache(): void
    {
        $pool = new ArrayAdapter();
        [$bypassUser, $bypassPassword] = self::bypassCredentials();

        $item = $pool->getItem(ConnectionProvisioningGuardMiddleware::cacheKeyFor(
            [...self::connectionParameters(), 'user' => $bypassUser],
        ));
        $pool->save($item->set(true));

        $connection = $this->guardedConnection($pool, connectAs: [$bypassUser, $bypassPassword]);

        self::assertSame(
            1,
            (int) $connection->fetchOne('SELECT 1'),
            'the cached verification must short-circuit — an actual re-check of this role throws, as the case above '
            . 'proves',
        );

        $connection->close();
    }

    /**
     * A FAILED VERIFICATION IS NOT CACHED, IN EITHER DIRECTION — the case that keeps the cache honest.
     *
     * Two things must hold and they pull in opposite directions. A failure must not be remembered as a SUCCESS, which
     * would let one bad start-up permanently disable the guard. And it must not be remembered as a FAILURE either:
     * the fix for a wrongly-provisioned database is to fix the database, and a cached failure would keep rejecting a
     * database that had just been repaired. So the pool must be untouched, which is what makes the write-only-on-pass
     * ordering in `verifyOnce()` load-bearing rather than incidental.
     */
    public function testAFailedVerificationLeavesTheCacheUntouched(): void
    {
        $pool = new ArrayAdapter();
        $connection = $this->guardedConnection($pool, connectAs: self::bypassCredentials());

        try {
            $connection->fetchOne('SELECT 1');
            self::fail('A role holding BYPASSRLS must be refused at acquisition.');
        } catch (\RuntimeException) {
            // The refusal itself is the subject of the case above; here only the CACHE state matters.
        }

        // ASSERTED AS A CACHE MISS, not as an empty pool: `ArrayAdapter` leaves a placeholder for a key that was
        // merely LOOKED UP, so `getValues() === []` fails on correct code. `isHit()` is the property that matters and
        // the only one the middleware acts on. [Verified: the empty-pool assertion failed with the key present and a
        // `null` value — a fixture detail masquerading as a defect.]
        self::assertFalse(
            $pool->getItem(ConnectionProvisioningGuardMiddleware::cacheKeyFor(
                [...self::connectionParameters(), 'user' => self::bypassCredentials()[0]],
            ))->isHit(),
            'a FAILED verification must not be cached — in either direction',
        );
    }

    /**
     * TWO ROLES PRODUCE TWO KEYS, so verifying one never vouches for the other.
     *
     * `doctrine.yaml` calls the default/owner split a security boundary and they are different roles with different
     * privileges. A cache keyed on anything coarser than (role, host, port, database) would let one satisfy the other,
     * which is the shape that would make the whole guard vacuous.
     *
     * A PURE assertion on `cacheKeyFor()`, with no connection: the first draft tried to prove this by acquiring as the
     * OWNER and counting cache entries, and the owner legitimately FAILS the guard — it owns the tenant tables, which
     * is precisely what `assertPolicedTablesAreBeyondThisRolesReach()` refuses. So that fixture could never have
     * produced a second passing verification, and the case was asking the wrong question anyway: what matters is that
     * the keys DIFFER, not that two verifications both pass.
     */
    public function testTwoRolesProduceTwoDifferentCacheKeys(): void
    {
        $base = self::connectionParameters();

        self::assertNotSame(
            ConnectionProvisioningGuardMiddleware::cacheKeyFor([...$base, 'user' => 'twes']),
            ConnectionProvisioningGuardMiddleware::cacheKeyFor([...$base, 'user' => 'twes_owner']),
            'the connecting role must be part of the key',
        );

        self::assertNotSame(
            ConnectionProvisioningGuardMiddleware::cacheKeyFor([...$base, 'user' => 'twes']),
            ConnectionProvisioningGuardMiddleware::cacheKeyFor([...$base, 'user' => 'twes', 'dbname' => 'other']),
            'the database must be part of the key',
        );

        // THE PASSWORD MUST NOT BE, and this is the direction worth pinning: a credential in a cache key is a
        // credential in a `var_dump`, in a cache-inspection command and in a filesystem path.
        self::assertSame(
            ConnectionProvisioningGuardMiddleware::cacheKeyFor([...$base, 'user' => 'twes', 'password' => 'a']),
            ConnectionProvisioningGuardMiddleware::cacheKeyFor([...$base, 'user' => 'twes', 'password' => 'b']),
            'the password must NOT be part of the key',
        );
    }

    /** @return array{string, string} the BYPASSRLS role, for which every policy is inert */
    private static function bypassCredentials(): array
    {
        return self::credentials('TWES_TEST_DB_BYPASS_USER', 'TWES_TEST_DB_BYPASS_PASSWORD');
    }

    /** @return array{string, string} */
    private static function credentials(string $userVar, string $passwordVar): array
    {
        $user = getenv($userVar);
        $password = getenv($passwordVar);

        if (!\is_string($user) || !\is_string($password)) {
            self::fail($userVar . ' and ' . $passwordVar . ' must be set.');
        }

        return [$user, $password];
    }

    /**
     * The host, port and database from `TWES_TEST_DSN`, without a role.
     *
     * @return array{driver: string, host: string, port: int, dbname: string}
     */
    private static function connectionParameters(): array
    {
        $dsn = getenv('TWES_TEST_DSN');

        if (!\is_string($dsn)) {
            self::fail('TWES_TEST_DSN must be set.');
        }

        $parts = [];

        foreach (explode(';', substr($dsn, \strlen('pgsql:'))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$key] = $value;
        }

        return [
            'driver' => 'pdo_pgsql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 5432),
            // The PROBE database, not the DSN's — see the note on the trait above.
            'dbname' => self::DATABASE,
        ];
    }

    /**
     * A DBAL connection with the provisioning guard installed.
     *
     * `assertRevokedCapabilities` is FALSE, matching `api/.env`: the provisioned test database deliberately grants
     * `TEMPORARY` and has not run the large-object revocations, so asserting them here would fail for a reason that is
     * about the fixture rather than about the guard. That gap is real and is stated in the class under test.
     *
     * @param array{string, string}|null $connectAs
     */
    private function guardedConnection(ArrayAdapter $pool, ?array $connectAs = null): Connection
    {
        [$connectUser, $connectPassword] = $connectAs
            ?? self::credentials('TWES_TEST_DB_USER', 'TWES_TEST_DB_PASSWORD');

        $configuration = new Configuration();
        $configuration->setMiddlewares([
            new ConnectionProvisioningGuardMiddleware(
                // `assertConnectionCannotBypassPolicies()` asks about the role the connection is ALREADY using
                // (`current_user` and the roles reachable from it), so there is nothing to inject — which is why this
                // test varies the CONNECTING role rather than a checker parameter.
                new PostgresRowLevelSecurityIsolation(),
                $pool,
                verificationTtl: 300,
                assertRevokedCapabilities: false,
            ),
        ]);

        try {
            return DriverManager::getConnection([
                ...self::connectionParameters(),
                'user' => $connectUser,
                'password' => $connectPassword,
            ], $configuration);
        } catch (\Doctrine\DBAL\Exception $exception) {
            $driverFailure = $exception->getPrevious();

            while (null !== $driverFailure && !$driverFailure instanceof \PDOException) {
                $driverFailure = $driverFailure->getPrevious();
            }

            self::fail(
                $driverFailure instanceof \PDOException
                    ? DatabaseRequirement::unreachable($driverFailure)
                    : 'Could not open a guarded connection: ' . $exception->getMessage(),
            );
        }
    }

    /**
     * **TWO DSNs DIFFERING ONLY IN `options` DO NOT SHARE A CACHE ENTRY.**
     *
     * `options` is the one connection parameter that arrives with a SESSION-scoped setting already applied —
     * `options=-ctwes.tenant_id=<other>` needs no privilege and no SQL, and a `ROLLBACK TO SAVEPOINT` can revert
     * `bind()`'s transaction-local value to it. It was absent from the cache key until round 2, so a clean connection
     * and a pinned one hashed identically and the first could have vouched for the second.
     *
     * Nothing exploited it, because `verifyOnce()` writes the cache only after every assertion has passed — so a pinned
     * DSN fails its own first connection and is never cached as good. That is safety by coincidence, which is not what
     * a tenancy boundary is built on, and this case is what makes it structural.
     *
     * Asserted on the KEY rather than through a connection, because the key is the whole property and building two
     * live connections would test PostgreSQL's `options` handling instead.
     */
    public function testTwoDsnsDifferingOnlyInOptionsDoNotShareACacheEntry(): void
    {
        $base = ['user' => 'twes', 'host' => '127.0.0.1', 'port' => 5432, 'dbname' => 'twes_in'];

        self::assertNotSame(
            ConnectionProvisioningGuardMiddleware::cacheKeyFor($base),
            ConnectionProvisioningGuardMiddleware::cacheKeyFor(
                [...$base, 'options' => '-ctwes.tenant_id=0199a5b2-0000-7000-8000-0000000009ff'],
            ),
            'a pinned DSN must not share a verification with a clean one: `options` carries a session-scoped tenant, '
            . 'which is precisely what the pin assertion inside the cached block exists to refuse',
        );

        // AND THE SAME PARAMETERS STILL HASH THE SAME, so the fix is not "make every key unique", which would retire
        // the cache and the 10.8 ms it exists to save.
        self::assertSame(
            ConnectionProvisioningGuardMiddleware::cacheKeyFor($base),
            ConnectionProvisioningGuardMiddleware::cacheKeyFor($base),
        );
    }
}

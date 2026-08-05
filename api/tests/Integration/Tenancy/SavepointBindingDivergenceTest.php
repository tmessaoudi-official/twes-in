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
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\Tests\Integration\DatabaseRequirement;

/**
 * A SAVEPOINT ROLLBACK REVERTS THE TENANT BINDING, AND IT IS REACHABLE TODAY.
 *
 * Residue R2-12 recorded this as "not reachable today (PDO forbids nested transactions)". That premise is
 * wrong: PDO forbids a nested `beginTransaction()`, not a `SAVEPOINT` issued as ordinary SQL — and a raw
 * `SAVEPOINT` is exactly what Doctrine emits for a nested transaction. So the defect needs no ORM, no
 * Doctrine and no future wave to reproduce; it needs nine lines.
 *
 * Why it matters more than it looks: `bind()` deliberately writes the tenant TRANSACTION-LOCALLY, because a
 * session-scoped binding would leak to whoever gets the connection next. `ROLLBACK TO SAVEPOINT` restores
 * transaction-local settings to their value at the savepoint — so a bind that happened inside the savepoint is
 * silently undone, the database goes back to scoping for the PREVIOUS tenant, and the PHP-side context still
 * believes the new one. Rows belonging to tenant A are then read and labelled as tenant B's. That is a
 * cross-tenant read with every isolation assertion in the suite still passing, because none of them crosses a
 * savepoint.
 *
 * This test does two things, and the second is the one that matters: it PROVES the divergence happens, so the
 * premise can never be re-recorded as an impossibility; and it proves `assertStillBoundTo()` catches it, so
 * the remedy is pinned rather than described.
 */
#[CoversClass(PostgresRowLevelSecurityIsolation::class)]
final class SavepointBindingDivergenceTest extends TestCase
{
    private const TENANT_A = '0199a5b2-0000-7000-8000-00000000000a';
    private const TENANT_B = '0199a5b2-0000-7000-8000-00000000000b';

    public function testASavepointRollbackRevertsTheBindingWhileTheContextStillBelievesTheNewTenant(): void
    {
        $connection = self::connect();
        $isolation = new PostgresRowLevelSecurityIsolation();

        $connection->beginTransaction();
        $isolation->bind($connection, InMemoryTenantContext::forTenant(TenantId::fromString(self::TENANT_A)));
        self::assertSame(self::TENANT_A, self::boundTenant($connection));

        // A savepoint, then a bind inside it. `bind()` refuses a second bind in the same transaction when the
        // session value is already set, so the savepoint is released first — which is exactly the sequence a
        // nested Doctrine transaction produces.
        $connection->exec('SAVEPOINT sp1');
        $connection->prepare('SELECT set_config(?, ?, true)')
            ->execute([PostgresRowLevelSecurityIsolation::TENANT_SETTING, self::TENANT_B]);
        self::assertSame(self::TENANT_B, self::boundTenant($connection));

        $connection->exec('ROLLBACK TO SAVEPOINT sp1');

        // THE DEFECT, asserted rather than described: the connection is back on tenant A.
        self::assertSame(
            self::TENANT_A,
            self::boundTenant($connection),
            'A savepoint rollback must be shown to revert the binding — if this ever stops being true, delete '
            . 'this test and the remedy it pins, but do not weaken it.',
        );

        $connection->rollBack();
    }

    public function testAssertStillBoundToCatchesTheDivergence(): void
    {
        $connection = self::connect();
        $isolation = new PostgresRowLevelSecurityIsolation();
        $context = InMemoryTenantContext::forTenant(TenantId::fromString(self::TENANT_A));

        $connection->beginTransaction();
        $isolation->bind($connection, $context);

        // Clean state: the check must PASS, or it would be a check that cannot pass.
        $isolation->assertStillBoundTo($connection, $context);

        $connection->exec('SAVEPOINT sp1');
        $connection->prepare('SELECT set_config(?, ?, true)')
            ->execute([PostgresRowLevelSecurityIsolation::TENANT_SETTING, self::TENANT_B]);
        $connection->exec('ROLLBACK TO SAVEPOINT sp1');

        // The application now believes tenant B; the connection is scoped to A.
        $believesB = InMemoryTenantContext::forTenant(TenantId::fromString(self::TENANT_B));

        // expectException, NOT try/catch/fail. Round 9 (P3): `self::fail()` throws
        // PHPUnit\Framework\AssertionFailedError, which IS a RuntimeException — so the catch block meant for
        // the production exception swallowed the test's own failure signal, and the mutant died only via the
        // inner string assertion. Weaken those and the case becomes vacuous.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant binding DIVERGED');

        try {
            $isolation->assertStillBoundTo($connection, $believesB);
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * F1 (round 9): the OTHER direction — the context holds no tenant while the connection is still bound.
     *
     * This is the state a cross-tenant report or migration runs in, and it used to throw `NoCurrentTenant`
     * rather than report the divergence. Worse, the prescribed call shape guards on `hasTenant()`, so a
     * correct caller ran no check at all here — and every statement stayed scoped to one tenant while the
     * application believed it was seeing all of them.
     */
    public function testATenantLessContextIsRefusedWhileTheConnectionIsStillBound(): void
    {
        $connection = self::connect();
        $isolation = new PostgresRowLevelSecurityIsolation();

        $connection->beginTransaction();
        $isolation->bind($connection, InMemoryTenantContext::forTenant(TenantId::fromString(self::TENANT_A)));

        try {
            $isolation->assertStillBoundTo($connection, InMemoryTenantContext::empty());
            self::fail('a tenant-less context must be refused while the connection is still bound.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('believes it holds NO tenant', $exception->getMessage());
            self::assertStringContainsString(self::TENANT_A, $exception->getMessage());
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * And the same check must PASS on a genuinely unbound connection, or it is a check that cannot pass —
     * which would make every legitimate cross-tenant operation impossible rather than merely guarded.
     */
    public function testATenantLessContextIsAcceptedOnAnUnboundConnection(): void
    {
        $connection = self::connect();
        $isolation = new PostgresRowLevelSecurityIsolation();

        $connection->beginTransaction();
        $isolation->assertStillBoundTo($connection, InMemoryTenantContext::empty());
        $connection->rollBack();

        // THE ASSERTION IS THAT THE CALL ABOVE DID NOT THROW: an unbound connection with a tenant-less context is a legitimate state
        // `addToAssertionCount(1)` rather than `assertTrue(true, ...)`, which PHPStan reports as an
        // assertion that can never fail. It is not decoration -- `failOnRisky` is on and PHPUnit fails a
        // test that records no assertion, so deleting it turns this accepting arm RED. The message moved
        // into the comment above because a passing assertion never prints one.
        $this->addToAssertionCount(1);
    }

    private static function boundTenant(\PDO $connection): string
    {
        $read = $connection->query(
            "SELECT coalesce(current_setting('" . PostgresRowLevelSecurityIsolation::TENANT_SETTING . "', true), '')",
        );
        self::assertNotFalse($read);

        return (string) $read->fetchColumn();
    }

    private static function connect(): \PDO
    {
        $dsn = getenv('TWES_TEST_DSN');
        $user = getenv('TWES_TEST_DB_USER');
        $password = getenv('TWES_TEST_DB_PASSWORD');

        if (!\is_string($dsn) || !\is_string($user) || !\is_string($password)) {
            self::fail('TWES_TEST_DSN, TWES_TEST_DB_USER and TWES_TEST_DB_PASSWORD must be set.');
        }

        try {
            return new \PDO($dsn, $user, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\PDOException $exception) {
            self::fail(DatabaseRequirement::unreachable($exception));
        }
    }
}

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

        try {
            $isolation->assertStillBoundTo($connection, $believesB);
            self::fail('assertStillBoundTo() must refuse a diverged binding.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Tenant binding DIVERGED', $exception->getMessage());
            self::assertStringContainsString(self::TENANT_B, $exception->getMessage());
            self::assertStringContainsString(self::TENANT_A, $exception->getMessage());
        } finally {
            $connection->rollBack();
        }
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

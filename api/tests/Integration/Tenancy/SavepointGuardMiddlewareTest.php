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
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twes\Infrastructure\Tenancy\Doctrine\SavepointTenantBindingConnection;
use Twes\Infrastructure\Tenancy\Doctrine\SavepointTenantBindingDriver;
use Twes\Infrastructure\Tenancy\Doctrine\SavepointTenantBindingMiddleware;
use Twes\Infrastructure\Tenancy\Doctrine\SavepointTenantBindingStatement;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\Tests\Integration\DatabaseRequirement;

/**
 * THE SAVEPOINT GUARD, THROUGH A REAL DBAL CONNECTION — including a savepoint nobody wrote.
 *
 * `SavepointBindingDivergenceTest` proves the DEFECT exists and that `assertStillBoundTo()` catches it when called.
 * This proves the WIRING: that the call happens without any repository remembering to make it.
 *
 * **The case that matters most is the nested `beginTransaction()`.** Nothing in it contains the word "savepoint" —
 * DBAL emits `SAVEPOINT DOCTRINE_2` and `ROLLBACK TO SAVEPOINT DOCTRINE_2` on the application's behalf, because
 * DBAL 4 has no other way to nest. That is precisely the shape a per-repository check cannot defend: the developer
 * who writes the nested transaction has no reason to think a tenancy guard is relevant.
 *
 * A HAND-BUILT DBAL connection rather than a booted kernel, deliberately: the kernel would prove the container
 * wiring and *not* the behaviour, and it is the behaviour that has a defect. `lint:container` plus the
 * `debug:container` scoping check cover the registration; this covers what the middleware does.
 */
#[CoversClass(SavepointTenantBindingMiddleware::class)]
#[CoversClass(SavepointTenantBindingDriver::class)]
#[CoversClass(SavepointTenantBindingConnection::class)]
#[CoversClass(SavepointTenantBindingStatement::class)]
final class SavepointGuardMiddlewareTest extends TestCase
{
    private const TENANT_A = '0199a5b2-0000-7000-8000-00000000010a';
    private const TENANT_B = '0199a5b2-0000-7000-8000-00000000010b';

    /**
     * A NESTED `beginTransaction()` — the savepoint the application never wrote.
     *
     * DBAL turns the second `beginTransaction()` into `SAVEPOINT DOCTRINE_2` and the matching `rollBack()` into
     * `ROLLBACK TO SAVEPOINT DOCTRINE_2`. The binding made inside that savepoint is reverted, the context still
     * believes the new tenant, and the guard has to fire from inside `rollBack()` with no call site of its own.
     */
    public function testANestedRollbackIsCaughtWithNoCallSiteAtAll(): void
    {
        $context = InMemoryTenantContext::forTenant(TenantId::fromString(self::TENANT_A));
        $connection = self::guardedConnection($context);
        $isolation = new PostgresRowLevelSecurityIsolation();

        $connection->beginTransaction();
        $isolation->bind($connection->getNativeConnection(), $context);

        // NESTED. No savepoint appears in this test's source; DBAL issues one because it has no alternative.
        $connection->beginTransaction();
        $connection->executeStatement(
            \sprintf(
                "SELECT set_config('%s', '%s', true)",
                PostgresRowLevelSecurityIsolation::TENANT_SETTING,
                self::TENANT_B,
            ),
        );
        $context->switchTo(TenantId::fromString(self::TENANT_B));

        // The inner rollback emits `ROLLBACK TO SAVEPOINT DOCTRINE_2`, which reverts the binding to tenant A while
        // the context holds B. The guard must turn that into an exception rather than a silent cross-tenant read.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant binding DIVERGED');

        try {
            $connection->rollBack();
        } finally {
            // The outer transaction is still open and the connection is being discarded; a plain rollback of the
            // whole thing is the only safe exit. Swallowed because the assertion above is the subject and DBAL's
            // nesting counter is left inconsistent by the throw.
            try {
                $connection->close();
            } catch (\Throwable) {
                // Nothing to report: the connection is discarded either way.
            }
        }
    }

    /**
     * AN EXPLICIT `ROLLBACK TO SAVEPOINT` through `executeStatement()`, which is the `exec()` route.
     *
     * Separate from the nested case because the two arrive at the middleware differently — DBAL's own savepoints
     * come from `createSavepoint()`/`rollbackSavepoint()`, application SQL from `executeStatement()` — and a
     * middleware that covered only one would pass whichever case was written first.
     */
    public function testAnApplicationIssuedSavepointRollbackIsCaught(): void
    {
        $context = InMemoryTenantContext::forTenant(TenantId::fromString(self::TENANT_A));
        $connection = self::guardedConnection($context);
        $isolation = new PostgresRowLevelSecurityIsolation();

        $connection->beginTransaction();
        $isolation->bind($connection->getNativeConnection(), $context);

        $connection->executeStatement('SAVEPOINT sp1');
        $connection->executeStatement(
            \sprintf(
                "SELECT set_config('%s', '%s', true)",
                PostgresRowLevelSecurityIsolation::TENANT_SETTING,
                self::TENANT_B,
            ),
        );
        $context->switchTo(TenantId::fromString(self::TENANT_B));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant binding DIVERGED');

        try {
            // The SAVEPOINT keyword omitted on purpose: PostgreSQL accepts it, and this is the spelling a literal
            // `stripos($sql, 'ROLLBACK TO SAVEPOINT')` would have missed. The recognition unit test covers all
            // four forms; this proves the grammar-derived rule holds through a real driver and a real rollback.
            $connection->executeStatement('ROLLBACK TO sp1');
        } finally {
            $connection->close();
        }
    }

    /**
     * A ROLLBACK THAT REVERTS TO THE SAME TENANT MUST NOT FIRE — the false-positive direction.
     *
     * Without this the guard could throw on every savepoint rollback and both cases above would still pass, which
     * would make it useless: a check that fires on correct code gets switched off, and `CLAUDE.md` § Gotchas
     * records that a control nobody trusts is worse than one openly owed. Here the bind happens BEFORE the
     * savepoint, so the rollback reverts to a state where the binding was already correct.
     */
    public function testARollbackThatDoesNotChangeTheTenantIsSilent(): void
    {
        $context = InMemoryTenantContext::forTenant(TenantId::fromString(self::TENANT_A));
        $connection = self::guardedConnection($context);
        $isolation = new PostgresRowLevelSecurityIsolation();

        $connection->beginTransaction();
        $isolation->bind($connection->getNativeConnection(), $context);

        $connection->executeStatement('SAVEPOINT sp1');
        $connection->executeStatement('SELECT 1');
        $connection->executeStatement('ROLLBACK TO SAVEPOINT sp1');

        // Reached at all, which is the assertion: no exception was thrown.
        self::assertSame(
            self::TENANT_A,
            self::boundTenant($connection),
            'the binding was made before the savepoint, so the rollback reverted to a correct state',
        );

        $connection->rollBack();
        $connection->close();
    }

    /**
     * A FULL ROLLBACK MUST NOT FIRE, and this is the case that would have made the guard unusable.
     *
     * `rollBack()` discards the transaction and its transaction-local binding, legitimately, on every rolled-back
     * request. If the guard treated that as a divergence — the GUC now empty while the context still holds a
     * tenant — it would throw on completely correct code, and the first person to hit it would remove the
     * middleware. DBAL's `rollBack()` reaches the driver as a distinct method rather than as exec'd SQL, which is
     * WHY it is safe; this test is what stops a future "let's also check on rollBack()" from being merged.
     */
    public function testAFullRollbackIsSilent(): void
    {
        $context = InMemoryTenantContext::forTenant(TenantId::fromString(self::TENANT_A));
        $connection = self::guardedConnection($context);
        $isolation = new PostgresRowLevelSecurityIsolation();

        $connection->beginTransaction();
        $isolation->bind($connection->getNativeConnection(), $context);
        $connection->rollBack();

        self::assertSame(0, $connection->getTransactionNestingLevel(), 'the transaction is over, with no exception');

        $connection->close();
    }

    private static function boundTenant(Connection $connection): string
    {
        return (string) $connection->fetchOne(
            \sprintf("SELECT coalesce(current_setting('%s', true), '')", PostgresRowLevelSecurityIsolation::TENANT_SETTING),
        );
    }

    /**
     * A DBAL connection with the guard middleware installed, built from the integration suite's own credentials.
     *
     * `DsnParser` rather than a hand-written parameter array — the same reasoning as
     * `scripts/gates/compose-config.sh`: call the consumer's own parser instead of a second, worse one. The suite's
     * `TWES_TEST_DSN` is a PDO DSN (`pgsql:host=…`) rather than a URL, so the parts are read from it explicitly and
     * the driver is named rather than inferred.
     */
    private static function guardedConnection(InMemoryTenantContext $context): Connection
    {
        $dsn = getenv('TWES_TEST_DSN');
        $user = getenv('TWES_TEST_DB_USER');
        $password = getenv('TWES_TEST_DB_PASSWORD');

        if (!\is_string($dsn) || !\is_string($user) || !\is_string($password)) {
            self::fail('TWES_TEST_DSN, TWES_TEST_DB_USER and TWES_TEST_DB_PASSWORD must be set.');
        }

        $configuration = new Configuration();
        $configuration->setMiddlewares([
            new SavepointTenantBindingMiddleware(new PostgresRowLevelSecurityIsolation(), $context),
        ]);

        try {
            $connection = DriverManager::getConnection(
                [...self::parametersFrom($dsn), 'user' => $user, 'password' => $password],
                $configuration,
            );

            // CONNECT EAGERLY. `getConnection()` is LAZY — it validates parameters and returns without touching
            // the server — so a dead cluster would otherwise surface as a confusing failure inside whichever
            // assertion happened to query first. Forcing it here means an unreachable database produces
            // `DatabaseRequirement`'s message, which names the two-cluster trap § Gotchas 2026-07-30 records: this
            // container runs PostgreSQL 16 and 18 both configured on 5432, so an authentication failure usually
            // means the cluster WITHOUT the tenancy roles won the port.
            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Doctrine\DBAL\Exception $exception) {
            $driverFailure = $exception->getPrevious();

            while (null !== $driverFailure && !$driverFailure instanceof \PDOException) {
                $driverFailure = $driverFailure->getPrevious();
            }

            self::fail(
                $driverFailure instanceof \PDOException
                    ? DatabaseRequirement::unreachable($driverFailure)
                    : 'Could not open a guarded DBAL connection: ' . $exception->getMessage(),
            );
        }
    }

    /**
     * @return array{driver: string, host: string, port: int, dbname: string}
     */
    private static function parametersFrom(string $pdoDsn): array
    {
        // `pgsql:host=127.0.0.1;port=5432;dbname=twes_in_test` — the shape `api/phpunit.xml` sets. Parsed here
        // rather than reused from `DsnParser` because that class expects a URL; keeping both spellings in one place
        // is what stops a second, divergent reader appearing later. Referenced so the import is not dead weight if
        // the suite ever moves to a URL: {@see DsnParser}.
        $parts = [];

        foreach (explode(';', substr($pdoDsn, \strlen('pgsql:'))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$key] = $value;
        }

        foreach (['host', 'port', 'dbname'] as $required) {
            if (!isset($parts[$required]) || '' === $parts[$required]) {
                self::fail(\sprintf('TWES_TEST_DSN is missing "%s=": %s', $required, $pdoDsn));
            }
        }

        return [
            'driver' => 'pdo_pgsql',
            'host' => $parts['host'],
            'port' => (int) $parts['port'],
            'dbname' => $parts['dbname'],
        ];
    }
}

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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;
use Twes\Infrastructure\Tenancy\Doctrine\SessionStateReleaser;
use Twes\Infrastructure\Tenancy\Exception\ConnectionMustBeEvicted;
use Twes\Tests\Integration\DatabaseRequirement;

/**
 * THE RELEASE HALF OF THE CONNECTION LIFECYCLE, against a real connection.
 *
 * `TenantIsolationTest` proves `discardSessionState()` clears what it claims to. This proves the WIRING: that a
 * long-running process actually calls it between units of work, and that a failed cleanup EVICTS rather than being
 * swallowed — which `build-waves.plan.md` names explicitly, *"catching and ignoring that exception re-creates the
 * eighth carrier in full."*
 *
 * The carriers under test are the ones that outlive a transaction and are therefore invisible to every
 * transaction-scoped isolation assertion: a TEMPORARY TABLE (whose `pg_temp` schema PRECEDES `public` in the search
 * path, so a temp table named after a policed one intercepts every unqualified reference to it), a `CURSOR WITH
 * HOLD`, and a `LISTEN` registration.
 */
#[CoversClass(SessionStateReleaser::class)]
final class SessionStateReleaserTest extends TestCase
{
    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }

    /**
     * IT IMPLEMENTS THE ECOSYSTEM'S OWN RESET CONTRACT, which is what makes Messenger call it.
     *
     * Asserted rather than assumed, because the whole wiring depends on it: `ServicesResetter` collects services by
     * this interface, so a refactor that dropped it would leave the class present, green and never invoked — the
     * "control that silently does not run" shape `CLAUDE.md` § Gotchas records four times.
     */
    public function testItIsAResettableServiceSoMessengerInvokesItBetweenMessages(): void
    {
        self::assertInstanceOf(ResetInterface::class, new SessionStateReleaser($this->connection()));
    }

    /**
     * A connection nobody opened is left alone, and not connected TO in order to be cleaned.
     *
     * The naive implementation calls `getNativeConnection()` unconditionally, which CONNECTS — so a reset between two
     * idle messages would open a backend purely to discard nothing. Asserted through `isConnected()` because that is
     * the observable consequence.
     */
    public function testAnUnopenedConnectionIsNotConnectedToJustToBeCleaned(): void
    {
        $connection = $this->connection();
        self::assertFalse($connection->isConnected(), 'DBAL connects lazily, so this starts closed');

        new SessionStateReleaser($connection)->reset();

        self::assertFalse($connection->isConnected(), 'reset must not have opened a backend');
    }

    /**
     * THE THREE CARRIERS THAT OUTLIVE A TRANSACTION ARE GONE AFTER A RESET.
     *
     * All three in one test on purpose: they share one `DISCARD ALL`, and splitting them would suggest three
     * mechanisms. What matters is that the state is gone, which is asserted per carrier.
     */
    public function testTemporaryTablesHeldCursorsAndListenRegistrationsAreAllCleared(): void
    {
        $connection = $this->connection();

        $connection->executeStatement('CREATE TEMPORARY TABLE zz_release_probe (id int)');
        $connection->executeStatement('LISTEN zz_release_channel');
        // A cursor must be declared inside a transaction; `WITH HOLD` is what makes it survive the COMMIT.
        $connection->beginTransaction();
        $connection->executeStatement('DECLARE zz_release_cursor CURSOR WITH HOLD FOR SELECT 1');
        $connection->commit();

        self::assertSame(1, $this->temporaryTableCount($connection), 'the temp table exists before the reset');
        self::assertSame(1, $this->heldCursorCount($connection), 'the held cursor exists before the reset');
        self::assertSame(1, $this->listenCount($connection), 'the LISTEN registration exists before the reset');

        new SessionStateReleaser($connection)->reset();

        self::assertSame(0, $this->temporaryTableCount($connection), 'the temp table must be gone');
        self::assertSame(0, $this->heldCursorCount($connection), 'the held cursor must be gone');
        self::assertSame(0, $this->listenCount($connection), 'the LISTEN registration must be gone');
    }

    /**
     * AN OPEN TRANSACTION IS ROLLED BACK, not refused.
     *
     * Round 12's correction, restated here as a wiring case: a unit of work is abandoned most often on an EXCEPTION
     * path, where a transaction is still open — so refusing would decline to clean exactly the connection that most
     * needs it. `DISCARD ALL` cannot run inside a transaction block (SQLSTATE 25001), so something has to give, and
     * for a cleanup routine the safe thing to give is the transaction.
     */
    public function testAnOpenTransactionIsRolledBackRatherThanRefused(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $connection->executeStatement('CREATE TEMPORARY TABLE zz_release_in_tx (id int)');

        new SessionStateReleaser($connection)->reset();

        self::assertSame(0, $this->temporaryTableCount($connection), 'cleaned despite the open transaction');
        // The DBAL wrapper's counter is NOT reset by a rollback issued underneath it, which is worth asserting
        // rather than discovering: the connection is safe to reuse at the SERVER, and the caller must still not
        // carry on using this wrapper as though its transaction were alive. That is why the release path belongs
        // between units of work rather than inside one.
        self::assertTrue(
            $connection->isTransactionActive(),
            'DBAL still believes a transaction is open — reset is a BETWEEN-units-of-work operation, not a rollback',
        );
    }

    /**
     * A FAILED CLEANUP EVICTS AND RETHROWS — the case the plan calls out by name.
     *
     * The cleanup is made to fail the way it actually fails in production: the backend goes away. Terminating this
     * session from a second connection is the honest reproduction, rather than mocking `\PDO` — a mocked driver
     * would prove the catch block runs and nothing about what a dead backend does.
     */
    public function testAFailedCleanupEvictsTheConnectionAndRethrows(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('SELECT 1');

        $victimPid = (int) $connection->fetchOne('SELECT pg_backend_pid()');

        $assassin = $this->freshConnection();
        $assassin->executeStatement('SELECT pg_terminate_backend(?)', [$victimPid]);
        $assassin->close();

        try {
            new SessionStateReleaser($connection)->reset();
            self::fail('A cleanup against a terminated backend must raise ConnectionMustBeEvicted.');
        } catch (ConnectionMustBeEvicted $evicted) {
            self::assertStringContainsString('must be EVICTED', $evicted->getMessage());
            // THE ORIGINAL DRIVER FAILURE IS PRESERVED. Round 13's finding: letting the raw PDOException escape from
            // a `finally`-shaped release path REPLACES the in-flight business exception, so the caller loses the
            // failure that caused the release. Carrying it as $previous is what keeps both.
            self::assertInstanceOf(\PDOException::class, $evicted->getPrevious(), 'the driver failure is preserved');
            self::assertFalse($connection->isConnected(), 'the connection is CLOSED, i.e. evicted');
        }
    }

    private function temporaryTableCount(Connection $connection): int
    {
        return (int) $connection->fetchOne(
            "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace"
            . " WHERE c.relpersistence = 't' AND n.nspname LIKE 'pg_temp%' AND c.relname LIKE 'zz_release%'",
        );
    }

    private function heldCursorCount(Connection $connection): int
    {
        return (int) $connection->fetchOne(
            "SELECT count(*) FROM pg_cursors WHERE is_holdable AND name LIKE 'zz_release%'",
        );
    }

    private function listenCount(Connection $connection): int
    {
        return (int) $connection->fetchOne(
            "SELECT count(*) FROM pg_listening_channels() AS c WHERE c LIKE 'zz_release%'",
        );
    }

    private function connection(): Connection
    {
        return $this->connection ??= $this->freshConnection();
    }

    /**
     * The RUNTIME role, not the owner: this is a per-connection cleanup on the credential the application actually
     * uses, and `DISCARD ALL` needs no privilege. `TWES_TEST_DSN`'s database is the provisioned test one, which
     * grants `TEMPORARY` — the column-fidelity suite needs a scratch table, and so does this test.
     */
    private function freshConnection(): Connection
    {
        $dsn = getenv('TWES_TEST_DSN');
        $user = getenv('TWES_TEST_DB_USER');
        $password = getenv('TWES_TEST_DB_PASSWORD');

        if (!\is_string($dsn) || !\is_string($user) || !\is_string($password)) {
            self::fail('TWES_TEST_DSN, TWES_TEST_DB_USER and TWES_TEST_DB_PASSWORD must be set.');
        }

        $parts = [];

        foreach (explode(';', substr($dsn, \strlen('pgsql:'))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$key] = $value;
        }

        try {
            return DriverManager::getConnection([
                'driver' => 'pdo_pgsql',
                'host' => $parts['host'] ?? '127.0.0.1',
                'port' => (int) ($parts['port'] ?? 5432),
                'dbname' => $parts['dbname'] ?? '',
                'user' => $user,
                'password' => $password,
            ]);
        } catch (\Doctrine\DBAL\Exception $exception) {
            $driverFailure = $exception->getPrevious();

            while (null !== $driverFailure && !$driverFailure instanceof \PDOException) {
                $driverFailure = $driverFailure->getPrevious();
            }

            self::fail(
                $driverFailure instanceof \PDOException
                    ? DatabaseRequirement::unreachable($driverFailure)
                    : 'Could not open a connection: ' . $exception->getMessage(),
            );
        }
    }
}

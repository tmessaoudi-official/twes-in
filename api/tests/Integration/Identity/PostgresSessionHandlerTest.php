<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Infrastructure\Session\PostgresSessionHandler;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The session table against the real PostgreSQL (the other tests run on a mock session storage). A request that only
 * reads neither waits for the session's lock nor writes what it holds, so one person's slow page no longer queues
 * their other requests; the row's expiry is the idle limit, pushed forward by a read at most once a minute.
 */
final class PostgresSessionHandlerTest extends KernelTestCase
{
    private const int IDLE = 1800;

    /** @var list<string> */
    private array $ids = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $pdo = $this->connection();
        foreach ($this->ids as $id) {
            $pdo->prepare('DELETE FROM sessions WHERE sess_id = ?')->execute([$id]);
        }
        parent::tearDown();
    }

    public function testAChangeIsWrittenWithTheIdleLimitAsItsExpiry(): void
    {
        $id = $this->id();

        $this->save($id, 'company|s:1:"A";', 'POST');

        [$data, $expiry] = $this->row($id);
        self::assertSame('company|s:1:"A";', $data);
        self::assertEqualsWithDelta(time() + self::IDLE, $expiry, 5, 'the idle limit, not the absolute one');
    }

    public function testAReadDoesNotWaitForTheLockAChangeHolds(): void
    {
        $id = $this->id();
        $this->save($id, 'company|s:1:"A";', 'POST');
        $changing = $this->handler('POST', $this->connection());
        $changing->open('', 'twes_session');
        $changing->read($id);

        // A second connection, which gives up after two seconds instead of waiting for ever if it were made to lock.
        $reading = $this->handler('GET', $this->connection("SET lock_timeout = '2s'"));
        $reading->open('', 'twes_session');
        $started = microtime(true);
        $read = $reading->read($id);

        self::assertSame('company|s:1:"A";', $read);
        self::assertLessThan(1.0, microtime(true) - $started);
        $reading->close();
        $changing->close();
    }

    public function testAReadNeverWritesWhatTheSessionHolds(): void
    {
        $id = $this->id();
        $this->save($id, 'company|s:1:"A";', 'POST');

        $this->save($id, 'company|s:1:"B";', 'GET');

        self::assertSame('company|s:1:"A";', $this->row($id)[0]);
    }

    public function testAReadPushesTheExpiryForwardAtMostOnceAMinute(): void
    {
        $id = $this->id();
        $this->save($id, 'company|s:1:"A";', 'POST');
        $this->connection()->prepare('UPDATE sessions SET sess_lifetime = ? WHERE sess_id = ?')->execute([time() + self::IDLE - 120, $id]);

        foreach (['write', 'updateTimestamp'] as $how) {
            $this->connection()->prepare('UPDATE sessions SET sess_lifetime = ? WHERE sess_id = ?')->execute([time() + self::IDLE - 120, $id]);
            $this->save($id, 'company|s:1:"A";', 'GET', $how);
            self::assertEqualsWithDelta(time() + self::IDLE, $this->row($id)[1], 5, $how.' pushes a stale expiry forward');

            $version = $this->version($id);
            $this->save($id, 'company|s:1:"A";', 'GET', $how);
            self::assertSame($version, $this->version($id), $how.' writes nothing a minute after the last push');
        }
    }

    public function testAReadPastTheIdleLimitFindsNoSession(): void
    {
        $id = $this->id();
        $this->save($id, 'company|s:1:"A";', 'POST');
        $this->connection()->prepare('UPDATE sessions SET sess_lifetime = ? WHERE sess_id = ?')->execute([time() - 1, $id]);

        $reading = $this->handler('GET', $this->connection());
        $reading->open('', 'twes_session');

        self::assertSame('', $reading->read($id));
        $reading->close();
    }

    public function testEndingASessionOnARequestThatOnlyReadsStillDeletesIt(): void
    {
        $id = $this->id();
        $this->save($id, 'company|s:1:"A";', 'POST');

        $reading = $this->handler('GET', $this->connection());
        $reading->open('', 'twes_session');
        $reading->read($id);
        $reading->destroy($id);
        $reading->close();

        self::assertSame([], $this->rows($id));
    }

    private function save(string $id, string $data, string $method, string $how = 'write'): void
    {
        $handler = $this->handler($method, $this->connection());
        $handler->open('', 'twes_session');
        $handler->read($id);
        'write' === $how ? $handler->write($id, $data) : $handler->updateTimestamp($id, $data);
        $handler->close();
    }

    private function handler(string $method, \PDO $pdo): PostgresSessionHandler
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/api/anything', $method));

        return new PostgresSessionHandler($pdo, $requests, self::IDLE);
    }

    /** A connection of its own to the test database, as the handler keeps its own beside Doctrine's. */
    private function connection(?string $setUp = null): \PDO
    {
        $params = static::getContainer()->get(Connection::class)->getParams();
        $text = static function (string $key) use ($params): string {
            $value = $params[$key] ?? null;
            self::assertTrue(\is_string($value) || \is_int($value), $key);

            return (string) $value;
        };
        $pdo = new \PDO(
            \sprintf('pgsql:host=%s;port=%s;dbname=%s', $text('host'), $text('port'), $text('dbname')),
            $text('user'),
            $text('password'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        if (null !== $setUp) {
            $pdo->exec($setUp);
        }

        return $pdo;
    }

    private function id(): string
    {
        return $this->ids[] = bin2hex(random_bytes(16));
    }

    /** @return array{0: string, 1: int} */
    private function row(string $id): array
    {
        $rows = $this->rows($id);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /** @return list<array{0: string, 1: int}> */
    private function rows(string $id): array
    {
        $statement = $this->connection()->prepare('SELECT sess_data, sess_lifetime FROM sessions WHERE sess_id = ?');
        $statement->execute([$id]);
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            self::assertIsArray($row);
            [$data, $expiry] = $row;
            $data = \is_resource($data) ? stream_get_contents($data) : $data;
            self::assertIsString($data);
            self::assertIsInt($expiry);
            $rows[] = [$data, $expiry];
        }

        return $rows;
    }

    /** The row's version: PostgreSQL writes a new one for every UPDATE that touches it. */
    private function version(string $id): string
    {
        $statement = $this->connection()->prepare('SELECT xmin::text FROM sessions WHERE sess_id = ?');
        $statement->execute([$id]);

        return (string) $statement->fetchColumn();
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Session;

use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * Sessions live in the "sessions" table of the application database (migration Version20260909000000 owns the DDL;
 * Doctrine's schema_filter hides the table from diffs), on a connection of their own, as Symfony's handler expects:
 * session writes happen after the response and must not ride on Doctrine's transaction.
 *
 * A request that can change the session (sign-in, a company switch, a second factor) takes the session's advisory
 * lock, so two such requests cannot overwrite each other. A request that only reads (GET, HEAD, OPTIONS) changes
 * nothing the session holds, so it neither waits for that lock nor writes the session back: one slow page no longer
 * queues everything else the same person asks for, and a read is not a write.
 *
 * The row's expiry is the idle limit: every write sets it to now plus the idle time, a read pushes it forward at most
 * once a minute, and PdoSessionHandler reads a row past its expiry as no session at all. The absolute limit is the
 * session's creation time, checked by SessionTimeoutListener.
 */
final class PostgresSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    /** How long a read may leave the expiry behind before it pushes it forward: the idle limit's precision. */
    private const int REFRESH_AFTER = 60;

    private ?\PDO $pdo = null;
    private ?PdoSessionHandler $locking = null;
    private ?PdoSessionHandler $reading = null;
    private ?PdoSessionHandler $current = null;
    private bool $onlyReads = false;

    public function __construct(
        #[Autowire(env: 'DATABASE_URL')] #[\SensitiveParameter] private readonly \PDO|string $connection,
        private readonly RequestStack $requests,
        #[Autowire(param: 'app.session.idle_ttl')] private readonly int $idleTtl,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        // Chosen once for the whole session cycle: the lock is taken at the first read, which strict mode makes
        // validateId(), and released at close(), so every call in between must reach the same handler.
        $request = $this->requests->getMainRequest();
        $this->onlyReads = null !== $request && $request->isMethodSafe();
        $this->current = $this->onlyReads ? $this->reading() : $this->locking();

        return $this->current->open($path, $name);
    }

    public function close(): bool
    {
        $closed = $this->current?->close() ?? true;
        $this->current = null;

        return $closed;
    }

    public function read(#[\SensitiveParameter] string $id): string
    {
        return $this->current()->read($id);
    }

    public function validateId(#[\SensitiveParameter] string $id): bool
    {
        return $this->current()->validateId($id);
    }

    public function write(#[\SensitiveParameter] string $id, string $data): bool
    {
        return $this->onlyReads ? $this->refresh($id) : $this->current()->write($id, $data);
    }

    public function updateTimestamp(#[\SensitiveParameter] string $id, string $data): bool
    {
        return $this->onlyReads ? $this->refresh($id) : $this->current()->updateTimestamp($id, $data);
    }

    /** Ending a session is a write even a read may make: the absolute limit, a row found expired. */
    public function destroy(#[\SensitiveParameter] string $id): bool
    {
        return $this->current()->destroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->current()->gc($max_lifetime);
    }

    /** Pushes the expiry forward, writing nothing when the last push is less than a minute old. */
    private function refresh(string $id): bool
    {
        $now = time();
        $this->pdo()->prepare('UPDATE sessions SET sess_lifetime = :expiry, sess_time = :now WHERE sess_id = :id AND sess_lifetime < :stale')
            ->execute(['expiry' => $now + $this->idleTtl, 'now' => $now, 'id' => $id, 'stale' => $now + $this->idleTtl - self::REFRESH_AFTER]);

        return true;
    }

    private function current(): PdoSessionHandler
    {
        return $this->current ?? throw new \LogicException('The session handler is used before it was opened.');
    }

    private function locking(): PdoSessionHandler
    {
        return $this->locking ??= new PdoSessionHandler($this->pdo(), ['lock_mode' => PdoSessionHandler::LOCK_ADVISORY, 'ttl' => $this->idleTtl]);
    }

    private function reading(): PdoSessionHandler
    {
        return $this->reading ??= new PdoSessionHandler($this->pdo(), ['lock_mode' => PdoSessionHandler::LOCK_NONE, 'ttl' => $this->idleTtl]);
    }

    /** One connection for both handlers, opened by the first session a process meets. */
    private function pdo(): \PDO
    {
        if ($this->connection instanceof \PDO) {
            return $this->connection;
        }
        if (null === $this->pdo) {
            $params = new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql'])->parse($this->connection);
            $this->pdo = new \PDO(
                \sprintf('pgsql:host=%s;port=%d;dbname=%s', $params['host'] ?? 'localhost', $params['port'] ?? 5432, $params['dbname'] ?? ''),
                $params['user'] ?? null,
                $params['password'] ?? null,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        }

        return $this->pdo;
    }
}

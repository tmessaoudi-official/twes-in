<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the queue from Messenger's own table, the disk under the stored files, and the archiver from PostgreSQL's
 * statistics. Each read is a count or a timestamp: nothing a record holds leaves the server.
 */
final readonly class DoctrineDeepHealthReader implements DeepHealthReader
{
    public function __construct(
        private Connection $connection,
        #[Autowire('%app.files.directory%')] private string $filesDirectory,
        #[Autowire('%kernel.project_dir%')] private string $projectDirectory,
        /** Set by compose.backup.yaml, the layer that ships the write-ahead log off-site. */
        #[Autowire(env: 'bool:BACKUP_EXPECTED')] private bool $backupExpected,
    ) {
    }

    public function read(): HealthReadings
    {
        return new HealthReadings($this->failedMessages(), $this->oldestWaitingSeconds(), $this->diskFree(), $this->archiving());
    }

    private function failedMessages(): int
    {
        return self::integer($this->connection->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name = 'failed'"));
    }

    /** Messenger writes its timestamps in UTC; a retry scheduled for later is not waiting yet, nor is one being handled. */
    private function oldestWaitingSeconds(): ?int
    {
        $age = $this->connection->fetchOne(<<<'SQL'
            -- database-clock: Messenger stamps available_at with the wall clock, and a health check measures real waiting
            SELECT floor(extract(epoch FROM (now() AT TIME ZONE 'UTC') - min(available_at)))
            FROM messenger_messages
            -- database-clock: the same wall clock, so a retry scheduled for later is not counted as waiting
            WHERE queue_name <> 'failed' AND delivered_at IS NULL AND available_at <= now() AT TIME ZONE 'UTC'
            SQL);

        return null === $age ? null : self::integer($age);
    }

    /** Stored in a bucket instead (FILES_STORAGE=s3), the files leave no directory: the API's own disk is read then. */
    private function diskFree(): float
    {
        $path = is_dir($this->filesDirectory) ? $this->filesDirectory : $this->projectDirectory;
        $free = disk_free_space($path);
        $total = disk_total_space($path);
        if (false === $free || false === $total || $total <= 0) {
            throw new \RuntimeException(\sprintf('The disk under %s cannot be measured.', $path));
        }

        return round($free / $total, 3);
    }

    private function archiving(): Archiving
    {
        if (!$this->backupExpected) {
            return Archiving::NotExpected;
        }
        if ('off' === $this->connection->fetchOne("SELECT current_setting('archive_mode')")) {
            return Archiving::Off;
        }
        $state = $this->connection->fetchAssociative(<<<'SQL'
            SELECT archived_count > 0 AS archived,
                   last_failed_time IS NOT NULL AND (last_archived_time IS NULL OR last_failed_time > last_archived_time) AS failing
            FROM pg_stat_archiver
            SQL);
        if (false === $state) {
            throw new \RuntimeException('pg_stat_archiver answered no row.');
        }
        if (true === $state['failing']) {
            return Archiving::Failing;
        }

        return true === $state['archived'] ? Archiving::Working : Archiving::NeverArchived;
    }

    private static function integer(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException(\sprintf('A whole number was expected from the database, got %s.', get_debug_type($value)));
    }
}

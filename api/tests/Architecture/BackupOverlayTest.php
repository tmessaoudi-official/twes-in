<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What the backup overlay promises that no drill can show: a disaster gives no warning, so the restore drill forces the
 * last WAL out before it restores, and what a real one loses is set here instead. A quiet database still hands its
 * WAL to the archive within archive_timeout, well inside the ruled quarter hour; the backup service only ever reads
 * the database's data and the stored files; and each repository is encrypted under a passphrase of the instance's own.
 */
final class BackupOverlayTest extends TestCase
{
    /** The longest a commit may wait before its WAL leaves for the bucket, in seconds: a third of the ruled 15 minutes. */
    private const int MOST_UNARCHIVED_SECONDS = 300;

    public function testAQuietDatabaseStillArchivesWithinFiveMinutes(): void
    {
        $settings = self::serverSettings();

        self::assertSame('replica', $settings['wal_level'] ?? null);
        self::assertSame('on', $settings['archive_mode'] ?? null);
        self::assertStringStartsWith('pgbackrest --stanza=twes archive-push', $settings['archive_command'] ?? '');
        $timeout = $settings['archive_timeout'] ?? '';
        self::assertMatchesRegularExpression('/^[1-9]\d*$/', $timeout, 'archive_timeout is a number of seconds');
        self::assertLessThanOrEqual(self::MOST_UNARCHIVED_SECONDS, (int) $timeout);
    }

    public function testTheBackupServiceOnlyReadsTheDatabaseAndTheFiles(): void
    {
        $volumes = self::service('backup')['volumes'] ?? null;
        self::assertIsArray($volumes);
        $mounts = [];
        foreach ($volumes as $volume) {
            self::assertIsString($volume);
            $parts = explode(':', $volume);
            $mounts[$parts[1] ?? ''] = $parts[2] ?? 'rw';
        }

        self::assertSame('ro', $mounts['/var/lib/postgresql'] ?? null, 'the database is read, never written, by the backups');
        self::assertSame('ro', $mounts['/files'] ?? null, 'the stored files are read, never written, by the backups');
    }

    public function testBothRepositoriesAreEncryptedUnderTheInstancesOwnPassphrase(): void
    {
        $conf = (string) file_get_contents(\dirname(__DIR__, 3).'/infra/postgres/pgbackrest.conf');
        self::assertMatchesRegularExpression('/^repo1-cipher-type=aes-256-cbc$/m', $conf);
        self::assertDoesNotMatchRegularExpression('/^repo1-cipher-pass=/m', $conf, 'the passphrase never sits in a committed file');

        $overlay = (string) file_get_contents(\dirname(__DIR__, 3).'/compose.backup.yaml');
        self::assertMatchesRegularExpression('/PGBACKREST_REPO1_CIPHER_PASS: "\$\{BACKUP_DATABASE_PASSPHRASE:\?/', $overlay);
        self::assertMatchesRegularExpression('/RESTIC_PASSWORD: "\$\{BACKUP_FILES_PASSPHRASE:\?/', $overlay);
    }

    /** @return array<string, string> each `-c name=value` the overlay starts the server with */
    private static function serverSettings(): array
    {
        $command = self::service('postgres')['command'] ?? null;
        self::assertIsArray($command);

        $settings = [];
        foreach ($command as $i => $word) {
            if ('-c' === $word) {
                $pair = $command[$i + 1] ?? null;
                self::assertIsString($pair);
                [$name, $value] = explode('=', $pair, 2) + [1 => ''];
                $settings[$name] = $value;
            }
        }

        return $settings;
    }

    /** @return array<array-key, mixed> */
    private static function service(string $name): array
    {
        // Compose's own tags (`!reset`, `!override`) are read as tagged values, which no assertion here looks into.
        $compose = Yaml::parseFile(\dirname(__DIR__, 3).'/compose.backup.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($compose);
        $services = $compose['services'] ?? null;
        self::assertIsArray($services);
        $service = $services[$name] ?? null;
        self::assertIsArray($service, $name.' is a service of compose.backup.yaml');

        return $service;
    }
}

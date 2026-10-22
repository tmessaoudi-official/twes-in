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
 * What the backup layers promise that no drill can show: a disaster gives no warning, so the restore drill forces the
 * last WAL out before it restores, and what a real one loses is set here instead. A quiet database still hands its
 * WAL to the archive within archive_timeout, well inside the ruled quarter hour; the backup service only ever reads
 * the database's data and the stored files; and each repository is encrypted under a passphrase of the instance's own.
 *
 * compose.backup.yaml is what archives; where to is a layer of its own: a server's bucket (compose.backup-bucket.yaml)
 * or a shop's folder (compose.backup-local.yaml), which compose.backup-offsite.yaml copies off site. Each destination
 * names the repositories to every service that reaches them, so what the database archives is where the backup service
 * and a restore look.
 */
final class BackupOverlayTest extends TestCase
{
    /** The longest a commit may wait before its WAL leaves for the archive, in seconds: a third of the ruled 15 minutes. */
    private const int MOST_UNARCHIVED_SECONDS = 300;

    /** The services that reach the repositories: the database archives, the backup service copies, a restore reads. */
    private const array REACHING = ['postgres', 'backup', 'restore'];

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
        foreach (['compose.backup.yaml', 'compose.backup-bucket.yaml', 'compose.backup-local.yaml', 'compose.backup-offsite.yaml'] as $file) {
            $mounts = self::mounts(self::service($file, 'backup'));

            self::assertNotSame('rw', $mounts['/var/lib/postgresql'] ?? 'ro', $file.': the database is read, never written, by the backups');
            self::assertNotSame('rw', $mounts['/files'] ?? 'ro', $file.': the stored files are read, never written, by the backups');
        }
        $mounts = self::mounts(self::service('compose.backup.yaml', 'backup'));
        self::assertSame('ro', $mounts['/var/lib/postgresql'] ?? null);
        self::assertSame('ro', $mounts['/files'] ?? null);
    }

    public function testBothRepositoriesAreEncryptedUnderTheInstancesOwnPassphrase(): void
    {
        $conf = self::read('infra/postgres/pgbackrest.conf');
        self::assertMatchesRegularExpression('/^repo1-cipher-type=aes-256-cbc$/m', $conf);
        self::assertDoesNotMatchRegularExpression('/^repo1-cipher-pass=/m', $conf, 'the passphrase never sits in a committed file');

        $base = self::read('compose.backup.yaml');
        // Each passphrase is a compose secret, a file the tool reads, never a value in an environment.
        self::assertMatchesRegularExpression('/backup_database_passphrase:\n\s+environment: BACKUP_DATABASE_PASSPHRASE$/m', $base);
        self::assertMatchesRegularExpression('/source: backup_database_passphrase\n\s+target: \/run\/secrets\/pgbackrest_repo1_cipher_pass$/m', $base);
        self::assertMatchesRegularExpression('/backup_files_passphrase:\n\s+environment: BACKUP_FILES_PASSPHRASE$/m', $base);
        self::assertMatchesRegularExpression('/source: backup_files_passphrase\n\s+target: \/run\/secrets\/restic_password$/m', $base);
        self::assertMatchesRegularExpression('/RESTIC_PASSWORD_FILE: \/run\/secrets\/restic_password$/m', $base);
        self::assertDoesNotMatchRegularExpression('/(PGBACKREST_REPO1_CIPHER_PASS|RESTIC_PASSWORD):/', $base);
    }

    public function testWhereTheRepositoriesLieIsTheDestinationsAlone(): void
    {
        $conf = self::read('infra/postgres/pgbackrest.conf');
        self::assertDoesNotMatchRegularExpression('/^repo1-type=/m', $conf, 'the kind of repository comes from the destination');
        self::assertDoesNotMatchRegularExpression('/^repo1-path=/m', $conf, 'the path comes from the destination alone');

        $base = self::read('compose.backup.yaml');
        foreach (['PGBACKREST_REPO1_TYPE', 'PGBACKREST_REPO1_PATH', 'RESTIC_REPOSITORY'] as $name) {
            self::assertStringNotContainsString($name, $base, 'compose.backup.yaml archives; a destination layer says where');
        }
    }

    public function testABucketHoldsBothRepositoriesUnderTheInstancesPrefix(): void
    {
        foreach (self::REACHING as $name) {
            $environment = self::environment('compose.backup-bucket.yaml', $name);

            self::assertSame('s3', $environment['PGBACKREST_REPO1_TYPE'] ?? null, $name);
            self::assertSame('/${BACKUP_S3_PREFIX:-}pgbackrest', $environment['PGBACKREST_REPO1_PATH'] ?? null, $name);
            foreach (['PGBACKREST_REPO1_S3_ENDPOINT', 'PGBACKREST_REPO1_S3_BUCKET', 'PGBACKREST_REPO1_S3_KEY'] as $key) {
                self::assertStringContainsString(':?', self::text($environment, $key, $name), $name.' '.$key.' is required: a server without it would archive nowhere');
            }
            // The secret key is a compose secret, under the name pgBackRest reads, required as every secret is.
            self::assertArrayNotHasKey('PGBACKREST_REPO1_S3_KEY_SECRET', $environment, $name);
            self::assertSame('backup_s3_secret', self::secrets('compose.backup-bucket.yaml', $name)['/run/secrets/pgbackrest_repo1_s3_key_secret'] ?? null, $name);
        }
        foreach (['backup', 'restore'] as $name) {
            self::assertSame('backup_s3_secret', self::secrets('compose.backup-bucket.yaml', $name)['/run/secrets/aws_secret_access_key'] ?? null, $name);
        }
        self::assertSame('BACKUP_S3_SECRET', self::topSecrets('compose.backup-bucket.yaml')['backup_s3_secret'] ?? null);
        foreach (['backup', 'restore'] as $name) {
            $environment = self::environment('compose.backup-bucket.yaml', $name);
            self::assertMatchesRegularExpression('#^s3:[^"]*/\$\{BACKUP_S3_BUCKET\}/\$\{BACKUP_S3_PREFIX:-\}restic$#', self::text($environment, 'RESTIC_REPOSITORY', $name), $name);
        }
    }

    public function testAShopsFolderHoldsBothRepositoriesForEveryServiceThatReachesThem(): void
    {
        foreach (self::REACHING as $name) {
            $service = self::service('compose.backup-local.yaml', $name);
            $environment = self::environment('compose.backup-local.yaml', $name);

            self::assertSame('posix', $environment['PGBACKREST_REPO1_TYPE'] ?? null, $name);
            self::assertSame('/backup/pgbackrest', $environment['PGBACKREST_REPO1_PATH'] ?? null, $name);
            self::assertSame('rw', self::mounts($service)['/backup'] ?? null, $name.' writes the shop\'s backup folder');
            self::assertContains('${BACKUP_DIRECTORY:-backup-local}:/backup', self::volumeLines($service), $name.': a host path when given, else the volume');
        }
        foreach (['backup', 'restore'] as $name) {
            self::assertSame('/backup/restic', self::environment('compose.backup-local.yaml', $name)['RESTIC_REPOSITORY'] ?? null, $name);
        }
    }

    /**
     * A shop's computer is off at night, when the server's schedule runs: there each step runs once it is due, which it
     * reads from the outcomes kept in a volume of their own, so a step's last success outlives the container.
     */
    public function testAShopsBackupsRunWhenDueFromOutcomesThatOutliveTheContainer(): void
    {
        self::assertSame('due', self::environment('compose.backup-local.yaml', 'backup')['BACKUP_SCHEDULE'] ?? null);
        self::assertArrayNotHasKey('BACKUP_SCHEDULE', self::environment('compose.backup.yaml', 'backup'), 'a server keeps the nightly schedule');
        self::assertContains('backup-outcome:/var/lib/twes-backup/outcome', self::volumeLines(self::service('compose.backup.yaml', 'backup')));
        $compose = Yaml::parseFile(\dirname(__DIR__, 3).'/compose.backup.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($compose);
        self::assertIsArray($compose['volumes'] ?? null);
        self::assertArrayHasKey('backup-outcome', $compose['volumes']);

        self::assertMatchesRegularExpression('#^\*/30 \* \* \* \* twes-backup due$#m', self::read('infra/postgres/backup/crontab.due'));
        self::assertStringContainsString('COPY backup/crontab.due /etc/twes-backup/crontab.due', self::read('infra/postgres/Dockerfile'));
    }

    public function testTheOffSiteCopyNeedsEveryValueOnceSwitchedOn(): void
    {
        foreach (['backup', 'restore'] as $name) {
            $environment = self::environment('compose.backup-offsite.yaml', $name);

            self::assertMatchesRegularExpression('#^s3:https://\$\{BACKUP_S3_ENDPOINT:\?[^}]*\}:\$\{BACKUP_S3_PORT:-443\}/\$\{BACKUP_S3_BUCKET:\?[^}]*\}/\$\{BACKUP_S3_PREFIX:-\}offsite$#', self::text($environment, 'OFFSITE_REPOSITORY', $name), $name);
            foreach (['AWS_ACCESS_KEY_ID', 'AWS_DEFAULT_REGION'] as $key) {
                self::assertStringContainsString(':?', self::text($environment, $key, $name), $name.' '.$key.' is required once the copy is on');
            }
            self::assertArrayNotHasKey('AWS_SECRET_ACCESS_KEY', $environment, $name);
            self::assertSame('backup_s3_secret', self::secrets('compose.backup-offsite.yaml', $name)['/run/secrets/aws_secret_access_key'] ?? null, $name);
        }
        self::assertSame('BACKUP_S3_SECRET', self::topSecrets('compose.backup-offsite.yaml')['backup_s3_secret'] ?? null);
    }

    /** @return array<string, string> each secret the service mounts, by its target, to its source */
    private static function secrets(string $file, string $name): array
    {
        $found = [];
        foreach ((array) (self::service($file, $name)['secrets'] ?? []) as $secret) {
            self::assertIsArray($secret, $name.' names each secret with its source and target');
            self::assertIsString($secret['source'] ?? null);
            self::assertIsString($secret['target'] ?? null);
            $found[$secret['target']] = $secret['source'];
        }

        return $found;
    }

    /** @return array<string, string> each secret the file declares, to the variable it comes from */
    private static function topSecrets(string $file): array
    {
        $compose = Yaml::parseFile(\dirname(__DIR__, 3).'/'.$file, Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($compose);
        $found = [];
        foreach ((array) ($compose['secrets'] ?? []) as $key => $secret) {
            self::assertIsArray($secret);
            self::assertIsString($secret['environment'] ?? null, $file.' '.$key.' comes from a variable');
            $found[(string) $key] = $secret['environment'];
        }

        return $found;
    }

    /** @param array<array-key, mixed> $environment */
    private static function text(array $environment, string $key, string $service): string
    {
        $value = $environment[$key] ?? null;
        self::assertIsString($value, $service.' sets '.$key);

        return $value;
    }

    /** @return array<string, string> each `-c name=value` the base starts the server with */
    private static function serverSettings(): array
    {
        $command = self::service('compose.backup.yaml', 'postgres')['command'] ?? null;
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

    /**
     * @param array<array-key, mixed> $service
     *
     * @return array<string, string> each mount's target and its mode
     */
    private static function mounts(array $service): array
    {
        $mounts = [];
        foreach (self::volumeLines($service) as $volume) {
            // A host path or a variable may hold a colon only before the target; the target and the mode never do.
            if (1 === preg_match('#:(/[^:]*)(?::(ro|rw))?$#', $volume, $match)) {
                $mounts[$match[1]] = $match[2] ?? 'rw';
            }
        }

        return $mounts;
    }

    /**
     * @param array<array-key, mixed> $service
     *
     * @return list<string>
     */
    private static function volumeLines(array $service): array
    {
        $volumes = $service['volumes'] ?? [];
        // `!override` keeps its list as the tagged value's own.
        if ($volumes instanceof \Symfony\Component\Yaml\Tag\TaggedValue) {
            $volumes = $volumes->getValue();
        }
        self::assertIsArray($volumes);
        $lines = [];
        foreach ($volumes as $volume) {
            self::assertIsString($volume);
            $lines[] = $volume;
        }

        return $lines;
    }

    /** @return array<array-key, mixed> */
    private static function environment(string $file, string $name): array
    {
        $environment = self::service($file, $name)['environment'] ?? null;
        self::assertIsArray($environment, $name.' of '.$file.' sets its environment');

        // `<<: *anchor` is merged by the parser itself.
        return $environment;
    }

    /** @return array<array-key, mixed> */
    private static function service(string $file, string $name): array
    {
        // Compose's own tags (`!reset`, `!override`) are read as tagged values.
        $compose = Yaml::parseFile(\dirname(__DIR__, 3).'/'.$file, Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($compose);
        $services = $compose['services'] ?? null;
        self::assertIsArray($services);
        $service = $services[$name] ?? null;
        self::assertIsArray($service, $name.' is a service of '.$file);

        return $service;
    }

    private static function read(string $file): string
    {
        $content = file_get_contents(\dirname(__DIR__, 3).'/'.$file);
        self::assertIsString($content, $file);

        return $content;
    }
}

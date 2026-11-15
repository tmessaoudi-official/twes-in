<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Files\Infrastructure\Flysystem\FlysystemFileStorage;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Infrastructure\Platform\OwnerPlatformContents;
use App\Tests\Concurrency\Support\StressDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;

/**
 * What app:platform:reset empties, for real, on a database this case makes for itself (`…_test<token>_reset`), migrated,
 * granted and seeded as an installation is, and dropped after: every table but the migrations list, read from the
 * database so a table added later is emptied too, and every stored file. The schema stays, so the installation starts
 * again from its migrations' last state, and a seed after it makes the operator again.
 */
final class OwnerPlatformContentsTest extends TestCase
{
    private string $database;
    private string $files;
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');
        $this->database = (StressDatabase::owner()['dbname'] ?? 'twes').'_test'.(\is_string($token) ? $token : '').'_reset';
        // Only ever a database of this case's own making: dropped again whatever happens.
        self::assertMatchesRegularExpression('/^twes_test\w*_reset$/', $this->database);
        $server = $this->server();
        $server->executeStatement('DROP DATABASE IF EXISTS '.$server->quoteSingleIdentifier($this->database).' WITH (FORCE)');
        $server->executeStatement('CREATE DATABASE '.$server->quoteSingleIdentifier($this->database));
        $server->close();
        $this->files = Rows::text(tempnam(sys_get_temp_dir(), 'twes-reset-files'));
        unlink($this->files);
        mkdir($this->files);
        $this->console('doctrine:migrations:migrate --no-interaction --allow-no-migration');
        $this->console('app:database:app-role --no-interaction');
        $this->console('app:seed --operator-password=a-long-operator-password');
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
        $server = $this->server();
        $server->executeStatement('DROP DATABASE IF EXISTS '.$server->quoteSingleIdentifier($this->database).' WITH (FORCE)');
        $server->close();
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->files, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->files);
    }

    public function testEveryTableButTheMigrationsListAndEveryStoredFileIsEmptied(): void
    {
        $connection = $this->connection();
        $migrations = $this->rows($connection, 'doctrine_migration_versions');
        self::assertGreaterThan(0, $migrations);
        self::assertGreaterThan(0, $this->rows($connection, 'company'), 'the seed made a company');
        self::assertGreaterThan(0, $this->rows($connection, 'user'), 'and its operator');
        $storage = new FlysystemFileStorage(new Filesystem(new LocalFilesystemAdapter($this->files)));
        $storage->write('companies/a/invoices/1.pdf', 'one');
        $storage->write('platform/logo.png', 'two');
        $tables = $this->tables($connection);

        $emptied = (new OwnerPlatformContents($connection, $storage))->empty();

        self::assertSame(['tables' => \count($tables) - 1, 'files' => 2], $emptied);
        $left = [];
        foreach ($tables as $table) {
            if ('doctrine_migration_versions' !== $table && 0 !== $this->rows($connection, $table)) {
                $left[] = $table;
            }
        }
        self::assertSame([], $left, 'every table but the migrations list is empty');
        self::assertSame($migrations, $this->rows($connection, 'doctrine_migration_versions'), 'the migrations list is kept');
        self::assertSame([], iterator_to_array($storage->keysUnder('')), 'no stored file is left');

        $this->console('app:seed --operator-password=a-long-operator-password');
        self::assertSame(1, $this->rows($connection, 'company'), 'the schema is whole: a seed makes the installation again');
    }

    /** @return list<string> */
    private function tables(Connection $connection): array
    {
        return Rows::texts($connection->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"));
    }

    private function rows(Connection $connection, string $table): int
    {
        return Rows::int($connection->fetchOne('SELECT COUNT(*) FROM '.$connection->quoteSingleIdentifier($table)));
    }

    private function connection(): Connection
    {
        return $this->connection ??= DriverManager::getConnection(['dbname' => $this->database] + StressDatabase::owner());
    }

    private function server(): Connection
    {
        return DriverManager::getConnection(['dbname' => 'postgres'] + StressDatabase::owner());
    }

    /** bin/console on this case's database, which TEST_TOKEN names through doctrine.yaml's suffix, and its files' folder. */
    private function console(string $command): void
    {
        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');
        $environment = ['TEST_TOKEN' => (\is_string($token) ? $token : '').'_reset', 'FILES_DIRECTORY' => $this->files];
        foreach (['APP_CACHE_DIR', 'APP_SHARE_DIR'] as $directory) {
            if (\is_string($_SERVER[$directory] ?? null)) {
                $environment[$directory] = $_SERVER[$directory];
            }
        }
        $line = 'env';
        foreach ($environment as $name => $value) {
            $line .= ' '.$name.'='.escapeshellarg($value);
        }
        exec($line.' php '.escapeshellarg(\dirname(__DIR__, 3).'/bin/console').' '.$command.' --env=test 2>&1', $output, $status);
        self::assertSame(0, $status, $command."\n".implode("\n", $output));
    }
}

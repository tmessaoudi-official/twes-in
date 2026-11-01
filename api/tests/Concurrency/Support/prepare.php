<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

// make stress's first step: the race tests' own database made anew (twes_test_stress, StressDatabase), migrated and
// granted to the application role as the test database is, and the test container built anew once without debug, the way
// every racer boots it, so K racers read one container instead of building one cache at the same time.

use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\StressDatabase;
use Doctrine\DBAL\DriverManager;

// Before the bootstrap, which reads the token for the cache and share directories; StressDatabase checks it after.
putenv('TEST_TOKEN=_stress');
$_SERVER['TEST_TOKEN'] = $_ENV['TEST_TOKEN'] = '_stress';
require dirname(__DIR__, 2).'/bootstrap.php';

$root = dirname(__DIR__, 3);
$name = StressDatabase::name();
$server = DriverManager::getConnection(['dbname' => 'postgres'] + StressDatabase::owner());
$server->executeStatement('DROP DATABASE IF EXISTS '.$server->quoteSingleIdentifier($name).' WITH (FORCE)');
$server->executeStatement('CREATE DATABASE '.$server->quoteSingleIdentifier($name));
$server->close();

foreach ([
    'doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test',
    'app:database:app-role --no-interaction --env=test',
    // Built anew, never warmed over: without debug a container is never checked against the source, and racers would
    // boot the one an earlier run compiled.
    'cache:clear --env=test --no-debug',
] as $command) {
    // The racers' own cache and share directories, which the bootstrap set for this token: bin/console reads no bootstrap.
    passthru(sprintf('TEST_TOKEN=%s APP_CACHE_DIR=%s APP_SHARE_DIR=%s php %s/bin/console %s --quiet', escapeshellarg(StressDatabase::TOKEN), escapeshellarg(Rows::text($_SERVER['APP_CACHE_DIR'] ?? null)), escapeshellarg(Rows::text($_SERVER['APP_SHARE_DIR'] ?? null)), escapeshellarg($root), $command), $status);
    if (0 !== $status) {
        fwrite(\STDERR, "prepare.php: bin/console $command failed ($status)\n");
        exit($status);
    }
}
fwrite(\STDERR, "prepare.php: $name made, migrated and granted; the test container built anew.\n");

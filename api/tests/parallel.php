<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

// Runs the API suite on paratest, one worker a processor (PARATEST_PROCESSES sets another count). `composer test` has
// just migrated the test database, twes_test, or twes_test<token> in a writer's worktree whose .env.test.local names
// a TEST_TOKEN; each worker gets a copy of it, its token followed by the worker's number (doctrine.yaml's suffix,
// tests/bootstrap.php), made from it as a template rather than migrated again, then granted to the application role
// as the test database is. Another tree's copies are never touched. The arguments given are paratest's.

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Fidry\CpuCoreCounter\CpuCoreCounter;
use Symfony\Component\Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$_SERVER['APP_ENV'] = 'test';
(new Dotenv())->bootEnv($root.'/.env');

$asked = getenv('PARATEST_PROCESSES');
$processes = is_string($asked) && '' !== $asked ? (int) $asked : (new CpuCoreCounter())->getCountWithFallback(2);
if ($processes < 1) {
    fwrite(\STDERR, "PARATEST_PROCESSES must be a positive number of workers.\n");
    exit(2);
}

$url = $_SERVER['DATABASE_MIGRATION_URL'] ?? null;
if (!is_string($url) || '' === $url) {
    fwrite(\STDERR, "DATABASE_MIGRATION_URL names no database to copy the test database from.\n");
    exit(2);
}
$owner = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
$base = $_SERVER['TEST_TOKEN'] ?? '';
$base = is_string($base) ? $base : '';
$database = ($owner['dbname'] ?? 'twes').'_test';
$source = $database.$base;
// The server's own database: a template is copied only while nobody, this connection included, is in it.
$server = DriverManager::getConnection(['dbname' => 'postgres'] + $owner);

$started = microtime(true);
for ($worker = 1; $worker <= $processes; ++$worker) {
    $token = '' === $base ? (string) $worker : $base.'_'.$worker;
    $copy = $server->quoteSingleIdentifier($database.$token);
    $server->executeStatement("DROP DATABASE IF EXISTS $copy WITH (FORCE)");
    $server->executeStatement("CREATE DATABASE $copy TEMPLATE ".$server->quoteSingleIdentifier($source));
    passthru(sprintf('TEST_TOKEN=%s php %s/bin/console app:database:app-role --no-interaction --env=test', escapeshellarg($token), escapeshellarg($root)), $granted);
    if (0 !== $granted) {
        exit($granted);
    }
}
$server->close();
fwrite(\STDERR, sprintf("%d test databases copied from %s in %.1f s.\n", $processes, $source, microtime(true) - $started));

/** @var list<string> $given */
$given = $_SERVER['argv'] ?? [];
$arguments = array_map(escapeshellarg(...), array_slice($given, 1));
// Paratest numbers its workers by TEST_TOKEN alone; the bootstrap puts the tree's token in front of that number.
passthru(sprintf('TWES_TEST_BASE=%s %s/vendor/bin/paratest --processes=%d %s', escapeshellarg($base), escapeshellarg($root), $processes, implode(' ', $arguments)), $status);
exit($status);

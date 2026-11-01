<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

// One racer of App\Tests\Concurrency\Support\Race, in a process of its own: it boots the test kernel on the stress
// database (the container warmed by make stress, never built here, so K racers never write one cache at once), waits
// at the barrier with every other racer, runs its step, and prints how it ended as one line the race reads back.
// Usage: racer.php <step class> <index> <barrier class id> <barrier object id> <base64 JSON arguments> <delay ms>

use App\Kernel;
use App\Tests\Concurrency\Support\Outcome;
use App\Tests\Concurrency\Support\RaceStep;
use Doctrine\DBAL\Exception\DriverException;

require dirname(__DIR__, 2).'/bootstrap.php';

/** @param array<string, mixed> $outcome */
function said(array $outcome): never
{
    echo 'RACE-OUTCOME '.json_encode($outcome, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    exit(0);
}

$given = $_SERVER['argv'] ?? [];
$given = is_array($given) ? array_values(array_map(static fn (mixed $word): string => is_string($word) ? $word : '', $given)) : [];
[, $step, $index, $barrierClass, $barrierObject, $encoded, $delayMs] = $given + ['', '', '', '', '', '', '0'];
$arguments = json_decode((string) base64_decode($encoded, true), true, flags: \JSON_THROW_ON_ERROR);
if (!is_array($arguments) || !is_a($step, RaceStep::class, true)) {
    fwrite(\STDERR, "racer.php: a RaceStep class and a JSON object of arguments are required\n");
    exit(2);
}

$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer();
$connection = $container->get('doctrine.dbal.default_connection');
// A contested lock fails the racer after this long rather than hanging the race: the harness counts it as a failure.
$connection->executeStatement("SET lock_timeout = '5s'");
$connection->executeStatement("SET statement_timeout = '60s'");
usleep(1000 * (int) $delayMs);
// The barrier: the race holds it exclusively; every racer queues for it shared, so all go the moment it is released.
$connection->executeQuery('SELECT pg_advisory_lock_shared(?, ?)', [(int) $barrierClass, (int) $barrierObject]);
$connection->executeQuery('SELECT pg_advisory_unlock_shared(?, ?)', [(int) $barrierClass, (int) $barrierObject]);

try {
    $named = [];
    foreach ($arguments as $key => $argument) {
        $named[(string) $key] = $argument;
    }
    $value = (new $step())->run($container, (int) $index, $named);
    said(['kind' => Outcome::OK, 'value' => $value]);
} catch (Throwable $failure) {
    $driver = $failure;
    while (null !== $driver && !$driver instanceof DriverException) {
        $driver = $driver->getPrevious();
    }
    $state = $driver?->getSQLState();
    said(['kind' => Outcome::kindOf($state), 'sqlState' => $state, 'message' => $failure::class.': '.$failure->getMessage()]);
}

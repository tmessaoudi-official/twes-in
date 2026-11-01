<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\RaceStep;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;

/**
 * The harness's own race: read a counter, think a moment, write it plus one. Without a lock two racers read the same
 * value and one increment is lost; with `FOR UPDATE` the second waits for the first. `locked` chooses which.
 */
final class IncrementCounter implements RaceStep
{
    public function run(ContainerInterface $container, int $index, array $arguments): mixed
    {
        $connection = $container->get('doctrine.dbal.default_connection');
        $lock = true === ($arguments['locked'] ?? false) ? ' FOR UPDATE' : '';

        return $connection->transactional(static function (Connection $connection) use ($lock): int {
            $read = Rows::int($connection->fetchOne('SELECT n FROM stress_counter WHERE id = 1'.$lock));
            usleep(50_000);
            $connection->executeStatement('UPDATE stress_counter SET n = ? WHERE id = 1', [$read + 1]);

            return $read;
        });
    }
}

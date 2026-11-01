<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Tests\Concurrency\Support\RaceStep;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;

/** Two racers lock the counter's two rows in opposite orders: PostgreSQL breaks the cycle by failing one with 40P01. */
final class LockInOppositeOrders implements RaceStep
{
    public function run(ContainerInterface $container, int $index, array $arguments): mixed
    {
        $connection = $container->get('doctrine.dbal.default_connection');
        [$first, $second] = 0 === $index % 2 ? [1, 2] : [2, 1];

        return $connection->transactional(static function (Connection $connection) use ($first, $second): int {
            $connection->fetchOne('SELECT n FROM stress_counter WHERE id = ? FOR UPDATE', [$first]);
            usleep(200_000);
            $connection->fetchOne('SELECT n FROM stress_counter WHERE id = ? FOR UPDATE', [$second]);

            return $first;
        });
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use Doctrine\DBAL\Connection;

final readonly class DoctrineDatabaseProbe implements DatabaseProbe
{
    public function __construct(private Connection $connection)
    {
    }

    public function isReachable(): bool
    {
        try {
            $one = $this->connection->executeQuery('SELECT 1')->fetchOne();

            return 1 === $one || '1' === $one;
        } catch (\Throwable) {
            // A health probe reports an unreachable database rather than raising: that report IS the endpoint's job.
            return false;
        }
    }
}

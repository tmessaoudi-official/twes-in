<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Session;

use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;

/**
 * The session rows past their expiry, which PdoSessionHandler already reads as no session at all: a row's
 * `sess_lifetime` is the moment it expires, in Unix seconds, as the handler writes it.
 */
final readonly class ExpiredSessions
{
    public function __construct(private Connection $connection)
    {
    }

    /** Takes away every row expired before the moment, in Unix seconds, and says how many went. */
    public function removeBefore(int $moment): int
    {
        return Rows::int($this->connection->executeStatement('DELETE FROM sessions WHERE sess_lifetime < ?', [$moment]));
    }
}

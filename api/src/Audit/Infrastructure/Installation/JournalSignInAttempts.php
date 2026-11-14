<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\Installation;

use App\Identity\Application\Login\LoginAudit;
use App\Installation\Application\SignInAttempts;
use App\Installation\Application\SignInCounts;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Every refused sign-in is journalled, outside any company, with whether it locked the account it guessed at (an
 * attempt on a locked account compares no password and locks nothing).
 */
final readonly class JournalSignInAttempts implements SignInAttempts
{
    public function __construct(private Connection $connection)
    {
    }

    public function since(\DateTimeImmutable $since): SignInCounts
    {
        $row = Rows::row($this->connection->fetchAssociative(
            "SELECT count(*) AS failed, count(*) FILTER (WHERE changes->>'locked' = 'true') AS locked FROM audit_log WHERE action = :action AND at >= :since",
            ['action' => LoginAudit::LOGIN_FAILED, 'since' => $since],
            ['since' => Types::DATETIME_IMMUTABLE],
        ));

        return new SignInCounts(Rows::int($row['failed']), Rows::int($row['locked']));
    }
}

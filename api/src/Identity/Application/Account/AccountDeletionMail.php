<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

/** Tells the person their account is closed, when its deletion is final, and that signing in before then takes it back. */
final readonly class AccountDeletionMail
{
    public function __construct(
        public string $to,
        public string $locale,
        public int $days,
        public \DateTimeImmutable $finalAt,
    ) {
    }
}

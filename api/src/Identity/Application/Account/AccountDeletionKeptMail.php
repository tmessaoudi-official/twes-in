<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

/** The mail telling a person their deletion was withdrawn, for they have become the only owner of these companies. */
final readonly class AccountDeletionKeptMail
{
    /** @param list<string> $companies their names */
    public function __construct(public string $to, public string $locale, public array $companies)
    {
    }
}

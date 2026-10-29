<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\CompanyProof;

/**
 * What a company asks of a member entering it: the password, with the authenticator code beside it where the company
 * requires one, or a passkey instead where the company takes one and the account holds one. A company requiring a second
 * factor of an account whose only one is a passkey takes that passkey alone: the password is not asked.
 */
final readonly class EntryProof
{
    public function __construct(
        public bool $passkey,
        public bool $code,
        public bool $password = true,
    ) {
    }
}

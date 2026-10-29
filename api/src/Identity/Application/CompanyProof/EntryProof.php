<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\CompanyProof;

/**
 * What a company asks of a member entering it: the password always, the authenticator code beside it where the company
 * requires one, and a passkey instead where the company takes one and the account holds one.
 */
final readonly class EntryProof
{
    public function __construct(
        public bool $passkey,
        public bool $code,
    ) {
    }
}

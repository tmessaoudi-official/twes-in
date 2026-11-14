<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\CompanyProof;

/**
 * What a prompt asking who is at the screen asks, a company's entry or a step-up (`StepUp/ScreenProofs` says which): the
 * password, with the authenticator code beside it where a second factor is required, or a passkey instead where one is
 * taken and the account holds one. Where a second factor is required of an account whose only one is a passkey, that
 * passkey alone: the password is not asked.
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

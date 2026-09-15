<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Identity\Domain\Passkey;

final readonly class RegisteredPasskey
{
    /** @param list<string> $recoveryCodes the raw codes when this passkey is the account's first factor, otherwise none */
    public function __construct(
        public Passkey $passkey,
        public array $recoveryCodes,
    ) {
    }
}

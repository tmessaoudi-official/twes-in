<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

/** What a replacement took away from an account, for the person running it to read. */
final readonly class ReplacedSecondFactors
{
    public function __construct(
        public bool $authenticator,
        public int $recoveryCodes,
        public int $passkeys,
    ) {
    }

    public function nothing(): bool
    {
        return !$this->authenticator && 0 === $this->recoveryCodes && 0 === $this->passkeys;
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return array_keys(array_filter([
            'authenticator' => $this->authenticator,
            'recovery_codes' => $this->recoveryCodes > 0,
            'passkeys' => $this->passkeys > 0,
        ]));
    }
}

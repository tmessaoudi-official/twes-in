<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Identity\Domain\PasskeyRepository;
use App\Identity\Domain\User;

/**
 * Whether a password alone is no longer enough for this account: an authenticator app in force, or at least one passkey.
 * Every gate that used to ask "has an authenticator" asks this instead, so a passkey-only account is held exactly the same.
 */
final readonly class SecondFactors
{
    public function __construct(private PasskeyRepository $passkeys)
    {
    }

    public function has(User $user): bool
    {
        return $user->hasTotp() || $this->passkeys->countFor($user) > 0;
    }
}

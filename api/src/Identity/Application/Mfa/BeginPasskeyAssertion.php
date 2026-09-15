<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Identity\Application\PasskeyCeremonies;
use App\Identity\Domain\Passkey;
use App\Identity\Domain\PasskeyRepository;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Request options naming only the account's own passkeys: for the second step of a login, and for replacing the recovery
 * codes from a session. At a login it runs after the password step, so saying that the account has no passkey tells
 * nobody anything the password did not already prove.
 */
final readonly class BeginPasskeyAssertion
{
    public function __construct(
        private UserRepository $users,
        private PasskeyRepository $passkeys,
        private PasskeyCeremonies $ceremonies,
    ) {
    }

    /** @throws PasskeyRefused when the account has no passkey */
    public function handle(Uuid $userId): string
    {
        $user = $this->users->ofId($userId) ?? throw new PasskeyRefused();
        $ids = array_map(static fn (Passkey $passkey): string => $passkey->getCredentialId(), $this->passkeys->ofUser($user));

        if ([] === $ids) {
            throw new PasskeyRefused();
        }

        return $this->ceremonies->requestOptions($ids);
    }
}

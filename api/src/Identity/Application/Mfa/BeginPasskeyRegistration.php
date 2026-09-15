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

/** Step one of registering a passkey: creation options that exclude the passkeys the account already has. */
final readonly class BeginPasskeyRegistration
{
    public function __construct(
        private UserRepository $users,
        private PasskeyRepository $passkeys,
        private PasskeyCeremonies $ceremonies,
    ) {
    }

    /** @throws PasskeyRefused when there is no such account */
    public function handle(Uuid $userId): string
    {
        $user = $this->users->ofId($userId) ?? throw new PasskeyRefused();

        return $this->ceremonies->creationOptions(
            $user->getId()->toRfc4122(),
            $user->getEmail()->value,
            $user->getDisplayName(),
            array_map(static fn (Passkey $passkey): string => $passkey->getCredentialId(), $this->passkeys->ofUser($user)),
        );
    }
}

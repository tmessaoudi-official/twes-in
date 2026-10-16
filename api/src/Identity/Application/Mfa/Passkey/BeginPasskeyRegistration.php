<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa\Passkey;

use App\Identity\Application\PasskeyCeremonies;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Identity\Domain\Model\Passkey;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Identity\Domain\Repository\UserRepository;
use Symfony\Component\Uid\Uuid;

/** Step one of registering a passkey: creation options that exclude the passkeys the account already has. */
final readonly class BeginPasskeyRegistration
{
    public function __construct(
        private UserRepository $users,
        private PasskeyRepository $passkeys,
        private PasskeyCeremonies $ceremonies,
        private ConfirmStepUp $stepUp,
    ) {
    }

    /**
     * @throws PasskeyRefused when there is no such account
     * @throws StepUpRequired when this sign-in has no fresh proof: a taken session must not plant a factor of its own
     */
    public function handle(Uuid $userId): string
    {
        if (!$this->stepUp->isRecent($userId)) {
            throw new StepUpRequired();
        }
        $user = $this->users->ofId($userId) ?? throw new PasskeyRefused();

        return $this->ceremonies->creationOptions(
            $user->getId()->toRfc4122(),
            $user->getEmail()->value,
            $user->getDisplayName(),
            array_map(static fn (Passkey $passkey): string => $passkey->getCredentialId(), $this->passkeys->ofUser($user)),
        );
    }
}

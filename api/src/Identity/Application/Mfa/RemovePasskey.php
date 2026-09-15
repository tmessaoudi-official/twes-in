<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Domain\PasskeyRepository;
use App\Identity\Domain\UserRepository;
use App\Tenancy\Application\Mfa\MfaRequirement;
use Symfony\Component\Uid\Uuid;

/**
 * Forgets a passkey, typically a lost or replaced device. The last factor of an account that a company requires to have
 * one stays: removing it would put the account straight back behind the enrolment interstitial.
 */
final readonly class RemovePasskey
{
    public const string REMOVED = 'auth.passkey_removed';

    public function __construct(
        private UserRepository $users,
        private PasskeyRepository $passkeys,
        private MfaRequirement $requirement,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @throws PasskeyNotFound  when the account has no such passkey
     * @throws LastSecondFactor when it is the last factor a company requires
     */
    public function handle(Uuid $userId, Uuid $passkeyId): void
    {
        $user = $this->users->ofId($userId);
        $passkey = null === $user ? null : $this->passkeys->ofUserAndId($user, $passkeyId);

        if (null === $user || null === $passkey) {
            throw new PasskeyNotFound();
        }

        if (!$user->hasTotp() && 1 === $this->passkeys->countFor($user) && $this->requirement->appliesTo($user)) {
            throw new LastSecondFactor();
        }

        $this->passkeys->remove($passkey);
        $this->audit->record(new AuditEntry('user', $user->getId(), self::REMOVED, $user->getId(), ['passkey' => $passkeyId->toRfc4122()]));
    }
}

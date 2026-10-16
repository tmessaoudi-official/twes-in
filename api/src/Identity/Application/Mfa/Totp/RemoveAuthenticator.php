<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa\Totp;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Application\Mfa\LastSecondFactor;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Mfa\MfaRequirement;
use Symfony\Component\Uid\Uuid;

/**
 * Forgets the account's authenticator app, typically a phone lost or replaced: another is then enrolled as the first
 * was. The last factor of an account that a company requires to have one stays, as a passkey does.
 */
final readonly class RemoveAuthenticator
{
    public const string REMOVED = 'auth.totp_removed';

    public function __construct(
        private UserRepository $users,
        private PasskeyRepository $passkeys,
        private MfaRequirement $requirement,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ConfirmStepUp $stepUp,
    ) {
    }

    /**
     * @throws AuthenticatorNotEnrolled when the account has no authenticator in force
     * @throws LastSecondFactor         when it is the last factor a company requires
     * @throws StepUpRequired           when this sign-in has no fresh proof: a taken session must not strip the account's factors
     */
    public function handle(Uuid $userId): void
    {
        if (!$this->stepUp->isRecent($userId)) {
            throw new StepUpRequired();
        }
        $this->transactions->run(function () use ($userId): void {
            $user = $this->users->ofId($userId);

            if (null === $user || !$user->hasTotp()) {
                throw new AuthenticatorNotEnrolled();
            }

            if (0 === $this->passkeys->countFor($user) && $this->requirement->appliesTo($user)) {
                throw new LastSecondFactor();
            }

            $user->disableTotp();
            $this->users->save($user);
            $this->audit->record(new AuditEntry('user', $user->getId(), self::REMOVED, $user->getId(), []));
        });
    }
}

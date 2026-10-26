<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa\Totp;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Mfa\MfaRequirement;
use Symfony\Component\Uid\Uuid;

/**
 * Forgets the account's authenticator app, typically a phone lost or replaced: another is then enrolled as the first
 * was. An account that must carry a second factor, an operator's or a member's of a company requiring one, keeps it
 * whatever passkeys stand beside it: a phone changed is a replacement there, which never leaves the account without one.
 */
final readonly class RemoveAuthenticator implements DeclaresAuditActions
{
    public const string REMOVED = 'auth.totp_removed';

    public function __construct(
        private UserRepository $users,
        private MfaRequirement $requirement,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ConfirmStepUp $stepUp,
        private SecurityNotices $notices,
    ) {
    }

    /**
     * @throws AuthenticatorNotEnrolled when the account has no authenticator in force
     * @throws AuthenticatorRequired    when the account must carry a second factor
     * @throws StepUpRequired           when this sign-in has no fresh proof: a taken session must not strip the account's factors
     */
    public function handle(Uuid $userId): void
    {
        // Refused before a proof is asked: one given for a removal that cannot happen would be asked for nothing.
        $this->refuseWhatCannotGo($userId);
        if (!$this->stepUp->isRecent($userId)) {
            throw new StepUpRequired();
        }
        $this->transactions->run(function () use ($userId): void {
            // Again inside: a company may have required it while the proof was given.
            $user = $this->refuseWhatCannotGo($userId);

            $user->disableTotp();
            $this->users->save($user);
            $this->audit->record(new AuditEntry('user', $user->getId(), self::REMOVED, $user->getId(), []));
            $this->notices->tell($user->getId(), SecurityChange::AuthenticatorRemoved);
        });
    }

    private function refuseWhatCannotGo(Uuid $userId): User
    {
        $user = $this->users->ofId($userId);

        if (null === $user || !$user->hasTotp()) {
            throw new AuthenticatorNotEnrolled();
        }

        if ($this->requirement->appliesTo($user)) {
            throw new AuthenticatorRequired();
        }

        return $user;
    }

    public function auditActions(): array
    {
        return [
            self::REMOVED => AuditScope::Outside,
        ];
    }
}

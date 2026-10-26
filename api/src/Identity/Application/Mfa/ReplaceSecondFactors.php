<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Identity\Domain\Repository\RecoveryCodeRepository;
use App\Identity\Domain\Repository\UserRepository;
use App\Identity\Domain\ValueObject\Email;
use App\Shared\Application\Transactions;

/**
 * The way back for an account that lost every factor it could sign in with, run by whoever runs the installation:
 * a required account (every operator) cannot remove its last factor on screen, and an operator's reset of another was
 * refused because it would make the operator a standing way round the second factor. What goes is the authenticator
 * and the recovery codes (and the passkeys when asked: one may live on the phone that was lost); every session ends,
 * and the next sign-in holds the account at the enrolment until it sets a factor up again.
 */
final readonly class ReplaceSecondFactors implements DeclaresAuditActions
{
    public const string REPLACED = 'auth.second_factors_replaced';

    public function __construct(
        private UserRepository $users,
        private RecoveryCodeRepository $recoveryCodes,
        private PasskeyRepository $passkeys,
        private AuditTrail $audit,
        private Transactions $transactions,
        private SecurityNotices $notices,
    ) {
    }

    /** @throws NoAccountHoldsAddress when no account holds that address */
    public function handle(Email $email, bool $withPasskeys): ReplacedSecondFactors
    {
        return $this->transactions->run(function () use ($email, $withPasskeys): ReplacedSecondFactors {
            $user = $this->users->ofEmail($email) ?? throw new NoAccountHoldsAddress();

            $authenticator = $user->hasTotp() || null !== $user->getTotpSecret();
            $codes = $this->recoveryCodes->countFor($user);
            $passkeys = $withPasskeys ? $this->passkeys->ofUser($user) : [];
            $replaced = new ReplacedSecondFactors($authenticator, $codes, \count($passkeys));
            if ($replaced->nothing()) {
                return $replaced;
            }

            $user->disableTotp();
            $this->recoveryCodes->replaceAll($user, []);
            foreach ($passkeys as $passkey) {
                $this->passkeys->remove($passkey);
            }
            $user->rotateSecurityStamp();
            $this->users->save($user);
            // Which kinds of factor went, never how many or which device: the trail says what happened, not what was held.
            $this->audit->record(new AuditEntry('user', $user->getId(), self::REPLACED, null, ['factors' => $replaced->kinds()]));
            $this->notices->tell($user->getId(), SecurityChange::FactorsReplaced);

            return $replaced;
        });
    }

    public function auditActions(): array
    {
        return [
            self::REPLACED => new Recorded('user', AuditScope::Outside),
        ];
    }
}

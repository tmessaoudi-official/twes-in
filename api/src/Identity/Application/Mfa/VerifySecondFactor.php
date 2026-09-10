<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Application\SecretCipher;
use App\Identity\Application\TotpCodes;
use App\Identity\Domain\RecoveryCode;
use App\Identity\Domain\RecoveryCodeRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Completes a login that owed a second factor.
 *
 * One field accepts either a six-digit code or a recovery code, because the two are told apart by their shape
 * and asking the person which kind they are holding buys nothing. Whichever it is, it is spent on use: a TOTP
 * timestep cannot be replayed inside its window, and a recovery code is deleted.
 */
final readonly class VerifySecondFactor
{
    public const string VERIFIED = 'auth.mfa_verified';
    public const string FAILED = 'auth.mfa_failed';
    public const string RECOVERY_USED = 'auth.recovery_code_used';

    public function __construct(
        private UserRepository $users,
        private RecoveryCodeRepository $recoveryCodes,
        private TotpCodes $totp,
        private SecretCipher $cipher,
        private AuditTrail $audit,
    ) {
    }

    /** @throws SecondFactorRefused */
    public function handle(Uuid $userId, string $code, ?\DateTimeImmutable $now = null): User
    {
        $now ??= new \DateTimeImmutable();
        $user = $this->users->ofId($userId);

        if (null === $user || !$user->hasTotp()) {
            // Nothing to verify against. Audited without a user id, because there may not be one.
            $this->audit->record(new AuditEntry('user', $user?->getId(), self::FAILED, null, ['reason' => 'no_factor']));

            throw new SecondFactorRefused();
        }

        if ($this->spendTotp($user, $code, $now) || $this->spendRecoveryCode($user, $code)) {
            $this->users->save($user);

            return $user;
        }

        $this->audit->record(new AuditEntry('user', $user->getId(), self::FAILED, $user->getId(), ['reason' => 'bad_code']));

        throw new SecondFactorRefused();
    }

    private function spendTotp(User $user, string $code, \DateTimeImmutable $now): bool
    {
        $secret = $user->getTotpSecret();

        if (null === $secret) {
            return false;
        }

        try {
            $timestep = $this->totp->verify($this->cipher->decrypt($secret), $code, $now);
        } catch (\RuntimeException) {
            // The stored secret cannot be read with the current key: a rotated APP_MFA_KEY, which means
            // re-enrolment. Refusing is right; pretending the code was wrong is not, so it is audited apart.
            $this->audit->record(new AuditEntry('user', $user->getId(), self::FAILED, $user->getId(), ['reason' => 'unreadable_secret']));

            return false;
        }

        if (null === $timestep) {
            return false;
        }

        try {
            $user->useTotpTimestep($timestep, $now);
        } catch (\DomainException) {
            // Arithmetically valid but already spent: a replay inside the same window.
            $this->audit->record(new AuditEntry('user', $user->getId(), self::FAILED, $user->getId(), ['reason' => 'replayed']));

            return false;
        }

        $this->audit->record(new AuditEntry('user', $user->getId(), self::VERIFIED, $user->getId(), ['method' => 'totp']));

        return true;
    }

    private function spendRecoveryCode(User $user, string $code): bool
    {
        $entry = $this->recoveryCodes->unspent($user, RecoveryCode::hashOf($code));

        if (null === $entry) {
            return false;
        }

        $this->recoveryCodes->spend($entry);
        $this->audit->record(new AuditEntry('user', $user->getId(), self::RECOVERY_USED, $user->getId(), [
            // What is left, so the SPA can say "two codes remaining" without a second round trip.
            'remaining' => $this->recoveryCodes->countFor($user),
        ]));

        return true;
    }
}

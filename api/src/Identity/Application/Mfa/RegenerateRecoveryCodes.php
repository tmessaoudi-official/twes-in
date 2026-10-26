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
use App\Identity\Application\SecretCipher;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use App\Identity\Application\TotpCodes;
use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\Transactions;
use Symfony\Component\Uid\Uuid;

/**
 * A new set of recovery codes for someone who has used or lost theirs (ruling of 2026-09-10: shown once, regenerable).
 *
 * The proof is a current authenticator code and never a recovery code: otherwise one leaked code would be enough to
 * replace all ten, and with them the only way back into an account whose authenticator is gone. The code is spent like
 * a login code, so it cannot be replayed for a second set inside its window. An account with a passkey can use that
 * instead (RegenerateRecoveryCodesWithPasskey).
 */
final readonly class RegenerateRecoveryCodes implements DeclaresAuditActions
{
    public const string REGENERATED = 'auth.recovery_codes_regenerated';

    public function __construct(
        private UserRepository $users,
        private IssueRecoveryCodes $issueRecoveryCodes,
        private TotpCodes $totp,
        private SecretCipher $cipher,
        private AuditTrail $audit,
        private Transactions $transactions,
        private SecurityNotices $notices,
    ) {
    }

    /**
     * @return list<string> the raw recovery codes, to be shown once
     *
     * @throws SecondFactorRefused    when there is no authenticator or the code does not verify
     * @throws SecondFactorUnreadable when the stored secret cannot be read with the current key
     */
    public function handle(Uuid $userId, string $code, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $user = $this->users->ofId($userId);
        $secret = $user?->getTotpSecret();

        if (null === $user || null === $secret || !$user->hasTotp()) {
            throw new SecondFactorRefused();
        }

        try {
            $plain = $this->cipher->decrypt($secret);
        } catch (\RuntimeException) {
            // A rotated APP_MFA_KEY: no code could verify, so this is not a guess (docs/SPEC.md § 8 row 26).
            throw new SecondFactorUnreadable();
        }

        $timestep = $this->totp->verify($plain, $code, $now);

        if (null === $timestep) {
            throw new SecondFactorRefused();
        }

        return $this->transactions->run(function () use ($userId, $timestep, $now): array {
            // Read again under the row lock: a login racing for the same code then waits, and one of the two finds it spent.
            $user = $this->users->lockedOfId($userId) ?? throw new SecondFactorRefused();
            try {
                $user->useTotpTimestep($timestep, $now);
            } catch (\DomainException) {
                // Already spent: the very code that confirmed the authenticator, or one that signed in a moment ago.
                throw new SecondFactorRefused();
            }

            $this->users->save($user);

            $codes = $this->issueRecoveryCodes->handle($user, $now);

            $this->audit->record(new AuditEntry('user', $user->getId(), self::REGENERATED, $user->getId(), ['count' => \count($codes), 'method' => 'totp']));
            $this->notices->tell($user->getId(), SecurityChange::RecoveryCodesRenewed);

            return $codes;
        });
    }

    public function auditActions(): array
    {
        return [
            self::REGENERATED => AuditScope::Outside,
        ];
    }
}

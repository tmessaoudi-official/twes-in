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
use App\Identity\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * A new set of recovery codes for someone who has used or lost theirs (ruling of 2026-09-10: shown once, regenerable).
 *
 * The proof is a current authenticator code and never a recovery code: otherwise one leaked code would be enough to
 * replace all ten, and with them the only way back into an account whose authenticator is gone. The code is spent like
 * a login code, so it cannot be replayed for a second set inside its window. An account with a passkey can use that
 * instead (RegenerateRecoveryCodesWithPasskey).
 */
final readonly class RegenerateRecoveryCodes
{
    public const string REGENERATED = 'auth.recovery_codes_regenerated';

    public function __construct(
        private UserRepository $users,
        private IssueRecoveryCodes $issueRecoveryCodes,
        private TotpCodes $totp,
        private SecretCipher $cipher,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @return list<string> the raw recovery codes, to be shown once
     *
     * @throws SecondFactorRefused when there is no authenticator or the code does not verify
     */
    public function handle(Uuid $userId, string $code, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $user = $this->users->ofId($userId);
        $secret = $user?->getTotpSecret();

        if (null === $user || null === $secret || !$user->hasTotp()) {
            throw new SecondFactorRefused();
        }

        $timestep = $this->totp->verify($this->cipher->decrypt($secret), $code, $now);

        if (null === $timestep) {
            throw new SecondFactorRefused();
        }

        try {
            $user->useTotpTimestep($timestep, $now);
        } catch (\DomainException) {
            // Already spent: the very code that confirmed the authenticator, or one that signed in a moment ago.
            throw new SecondFactorRefused();
        }

        $this->users->save($user);

        $codes = $this->issueRecoveryCodes->handle($user, $now);

        $this->audit->record(new AuditEntry('user', $user->getId(), self::REGENERATED, $user->getId(), ['count' => \count($codes), 'method' => 'totp']));

        return $codes;
    }
}

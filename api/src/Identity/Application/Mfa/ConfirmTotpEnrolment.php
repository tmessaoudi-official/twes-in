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
use App\Identity\Domain\RecoveryCodeEntry;
use App\Identity\Domain\RecoveryCodeRepository;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Step two: one real code turns the pending secret into a factor, and the recovery codes are issued.
 *
 * The raw codes are returned here and nowhere else, ever — what is stored is their SHA-256. Losing them means
 * generating a new set, which is why the ruling of 2026-09-10 says they are shown once and regenerable.
 */
final readonly class ConfirmTotpEnrolment
{
    public const string ENROLLED = 'auth.mfa_enrolled';

    public function __construct(
        private UserRepository $users,
        private RecoveryCodeRepository $recoveryCodes,
        private TotpCodes $totp,
        private SecretCipher $cipher,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @return list<string> the raw recovery codes, to be shown once
     *
     * @throws SecondFactorRefused when the code does not match the pending secret
     */
    public function handle(Uuid $userId, string $code, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $user = $this->users->ofId($userId);
        $pending = $user?->getTotpSecret();

        if (null === $user || null === $pending || $user->hasTotp()) {
            // Nothing pending, or a factor is already on: confirming again would be a way to reissue
            // recovery codes without proving anything.
            throw new SecondFactorRefused();
        }

        $timestep = $this->totp->verify($this->cipher->decrypt($pending), $code, $now);

        if (null === $timestep) {
            throw new SecondFactorRefused();
        }

        $user->confirmTotpEnrolment($timestep, $now);
        $this->users->save($user);

        $codes = RecoveryCode::generateSet();
        $this->recoveryCodes->replaceAll($user, array_map(
            static fn (RecoveryCode $c): RecoveryCodeEntry => new RecoveryCodeEntry($user, $c->hash(), $now),
            $codes,
        ));

        $this->audit->record(new AuditEntry('user', $user->getId(), self::ENROLLED, $user->getId(), ['method' => 'totp']));

        return array_map(static fn (RecoveryCode $c): string => $c->raw, $codes);
    }
}

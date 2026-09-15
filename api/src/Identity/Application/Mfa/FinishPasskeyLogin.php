<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Completes a login that owed a second factor with a passkey instead of a code. What makes a passkey proof is
 * PasskeyAssertions; this records the outcome the way a code's is recorded.
 */
final readonly class FinishPasskeyLogin
{
    public function __construct(
        private UserRepository $users,
        private PasskeyAssertions $assertions,
        private AuditTrail $audit,
    ) {
    }

    /** @throws PasskeyRefused */
    public function handle(Uuid $userId, string $optionsJson, string $credentialJson, ?\DateTimeImmutable $now = null): User
    {
        $now ??= new \DateTimeImmutable();
        $user = $this->users->ofId($userId);

        if (null === $user) {
            $this->audit->record(new AuditEntry('user', null, VerifySecondFactor::FAILED, null, ['reason' => 'no_factor']));

            throw new PasskeyRefused();
        }

        try {
            $this->assertions->verify($user, $optionsJson, $credentialJson, $now);
        } catch (PasskeyRefused $refused) {
            $this->audit->record(new AuditEntry('user', $user->getId(), VerifySecondFactor::FAILED, $user->getId(), ['reason' => $refused->reason()]));

            throw $refused;
        }

        $this->audit->record(new AuditEntry('user', $user->getId(), VerifySecondFactor::VERIFIED, $user->getId(), ['method' => 'passkey']));

        return $user;
    }
}

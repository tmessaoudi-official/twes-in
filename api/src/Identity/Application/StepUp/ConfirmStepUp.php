<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\StepUp;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Application\Mfa\PasskeyAssertions;
use App\Identity\Application\Mfa\PasskeyRefused;
use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * A signed-in person proves who they are again, at the moment of something a stranger at the same screen must not be
 * able to do: leaving customer view is the first, since a customer looking at the screen would otherwise only have to
 * press the button. The proof is the password or one of the account's passkeys; a recovery code or an authenticator
 * code is not enough, as they are for replacing the recovery codes. Success and refusal are both audited.
 */
final readonly class ConfirmStepUp
{
    public const string CONFIRMED = 'auth.step_up';
    public const string REFUSED = 'auth.step_up_refused';

    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private PasskeyAssertions $assertions,
        private AuditTrail $audit,
    ) {
    }

    /** @throws StepUpRefused when the password is not the account's */
    public function withPassword(Uuid $userId, string $password, ?\DateTimeImmutable $now = null): void
    {
        $user = $this->users->ofId($userId);

        if (null === $user || '' === $password || !$this->hasher->verify($user->getPasswordHash(), $password)) {
            $this->audit->record(new AuditEntry('user', $userId, self::REFUSED, $userId, ['method' => 'password']));

            throw new StepUpRefused();
        }

        $this->audit->record(new AuditEntry('user', $userId, self::CONFIRMED, $userId, ['method' => 'password']));
    }

    /**
     * @throws StepUpRefused when the credential is not one of the account's passkeys answering the options
     */
    public function withPasskey(Uuid $userId, string $optionsJson, string $credentialJson, ?\DateTimeImmutable $now = null): void
    {
        $user = $this->users->ofId($userId);

        try {
            if (null === $user) {
                throw new StepUpRefused();
            }
            // The passkey's new signature counter is saved by the verification.
            $this->assertions->verify($user, $optionsJson, $credentialJson, $now ?? new \DateTimeImmutable());
        } catch (PasskeyRefused|StepUpRefused) {
            $this->audit->record(new AuditEntry('user', $userId, self::REFUSED, $userId, ['method' => 'passkey']));

            throw new StepUpRefused();
        }

        $this->audit->record(new AuditEntry('user', $userId, self::CONFIRMED, $userId, ['method' => 'passkey']));
    }
}

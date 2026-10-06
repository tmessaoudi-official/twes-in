<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\StepUp;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Application\Login\PasswordAttempts;
use App\Identity\Application\Mfa\PasskeyAssertions;
use App\Identity\Application\Mfa\PasskeyRefused;
use App\Identity\Domain\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * A signed-in person proves who they are again, at the moment of something a stranger at the same screen must not be
 * able to do: leaving customer view is the first, since a customer looking at the screen would otherwise only have to
 * press the button. The proof is the password or one of the account's passkeys; a recovery code or an authenticator
 * code is not enough, as they are for replacing the recovery codes. Success and refusal are both audited.
 *
 * A proof the API checks itself (an export: docs/SPEC.md § 7, audit H-b2) holds for a few minutes of this sign-in, so
 * several files in a row ask once, and a screen left open afterwards hands nothing to the next person.
 */
final readonly class ConfirmStepUp
{
    public const string CONFIRMED = 'auth.step_up';
    public const string REFUSED = 'auth.step_up_refused';

    public function __construct(
        private UserRepository $users,
        private PasswordAttempts $passwords,
        private PasskeyAssertions $assertions,
        private AuditTrail $audit,
        private StepUpProofs $proofs,
        private ClockInterface $clock,
        #[Autowire(param: 'app.step_up.valid_for')]
        private string $validFor,
    ) {
    }

    /** Whether this account proved itself in this sign-in within the last few minutes. */
    public function isRecent(Uuid $userId): bool
    {
        $at = $this->proofs->lastFor($userId);

        return null !== $at && $this->clock->now() <= $at->add(new \DateInterval($this->validFor));
    }

    /** @throws StepUpRefused when the password is not the account's */
    public function withPassword(Uuid $userId, string $password, ?\DateTimeImmutable $now = null): void
    {
        $user = $this->users->ofId($userId);

        if (null === $user || !$this->passwords->matches($user, $password, 'step_up')) {
            $this->audit->record(new AuditEntry('user', $userId, self::REFUSED, $userId, ['method' => 'password']));

            throw new StepUpRefused();
        }

        $this->audit->record(new AuditEntry('user', $userId, self::CONFIRMED, $userId, ['method' => 'password']));
        $this->proofs->remember($userId, $now ?? $this->clock->now());
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
            $this->assertions->verify($user, $optionsJson, $credentialJson, $now ?? $this->clock->now());
        } catch (PasskeyRefused|StepUpRefused) {
            $this->audit->record(new AuditEntry('user', $userId, self::REFUSED, $userId, ['method' => 'passkey']));

            throw new StepUpRefused();
        }

        $this->audit->record(new AuditEntry('user', $userId, self::CONFIRMED, $userId, ['method' => 'passkey']));
        $this->proofs->remember($userId, $now ?? $this->clock->now());
    }
}

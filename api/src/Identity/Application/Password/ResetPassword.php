<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Password;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\PasswordResetRepository;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\UserSessionRepository;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;

/**
 * Whoever holds a live reset link chooses a new password for the account it was sent for. The password follows the same
 * rules as a change, a refusal leaves the link usable, and success spends the link, voids any other, and ends every
 * session of the account. A second factor is not skipped: it is asked at the next sign-in as ever.
 */
final readonly class ResetPassword
{
    public const string RESET = 'auth.password_reset';

    public function __construct(
        private UserRepository $users,
        private PasswordResetRepository $resets,
        private PasswordHasher $hasher,
        private NewPasswordPolicy $policy,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        private UserSessionRepository $sessions,
    ) {
    }

    /** @throws ResetLinkNotUsable|NewPasswordRefused */
    public function handle(string $rawToken, string $newPassword): void
    {
        try {
            $hash = PasswordResetToken::fromRaw($rawToken)->hash();
        } catch (\InvalidArgumentException $malformed) {
            throw new ResetLinkNotUsable('That link cannot be used.', 0, $malformed);
        }

        $now = $this->clock->now();
        $reset = $this->resets->ofTokenHash($hash);
        if (null === $reset || !$reset->isUsableAt($now)) {
            throw new ResetLinkNotUsable('That link cannot be used.');
        }

        // Asked before the transaction opens: the breach check is a call to another service.
        $breached = $this->policy->check($newPassword);

        $this->transactions->run(function () use ($hash, $newPassword, $breached, $now): void {
            // Read again under the row's lock: two uses of one link at once set one password, and the second is refused.
            $reset = $this->resets->lockedOfTokenHash($hash);
            if (null === $reset || !$reset->isUsableAt($now)) {
                throw new ResetLinkNotUsable('That link cannot be used.');
            }
            $user = $reset->getUser();
            $user->setPasswordHash($this->hasher->hash($newPassword), $now);
            $this->users->save($user);
            $this->sessions->revokeEveryOf($user, $now);
            $reset->markUsed($now);
            $this->resets->save($reset);
            foreach ($this->resets->openFor($user) as $other) {
                $this->resets->remove($other);
            }
            if (null === $breached) {
                $this->audit->record(new AuditEntry('user', $user->getId(), ChangePassword::BREACH_CHECK_SKIPPED, $user->getId(), ['reason' => 'the breach service could not be reached']));
            }
            $this->audit->record(new AuditEntry('user', $user->getId(), self::RESET, $user->getId()));
        });
    }
}

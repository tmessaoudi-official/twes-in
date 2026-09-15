<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Domain\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * The account lockout: consecutive wrong credentials lock the account for a while. A wrong password counts, and
 * so does a wrong second-factor code — both are guesses at the same login, and a code that could be guessed
 * forever at the limiter's pace is guessed eventually (docs/SPEC.md § 8 row 22, review S6). Only a wrong
 * credential counts: a refusal because the account is already locked (or disabled, or throttled) must not touch
 * the counter, otherwise retrying would extend the lock without end, which is a way to keep someone out of
 * their own account.
 */
final readonly class RecordFailedLogin
{
    public function __construct(
        private UserRepository $users,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private int $lockAfterFailures,
        private string $lockDuration,
    ) {
    }

    public function handle(FailedLoginAttempt $attempt): void
    {
        $user = null === $attempt->userId ? null : $this->users->ofId($attempt->userId);
        $changes = ['email' => $attempt->email, 'reason' => $attempt->reason];

        if (null !== $user && $attempt->wrongCredential) {
            $now = $this->clock->now();
            $user->recordFailedLogin($now, $this->lockAfterFailures, new \DateInterval($this->lockDuration));
            $this->users->save($user);
            $changes['failed_login_count'] = $user->getFailedLoginCount();
            $changes['locked'] = $user->isLockedAt($now);
        }

        $this->audit->record(new AuditEntry(LoginAudit::ENTITY_TYPE, $user?->getId(), LoginAudit::LOGIN_FAILED, null, $changes));
    }
}

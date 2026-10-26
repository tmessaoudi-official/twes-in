<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use App\Identity\Domain\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The account lockout: consecutive wrong credentials lock the account for a while. A wrong password counts, and
 * so does a wrong second-factor code — both are guesses at the same login, and a code that could be guessed
 * forever at the limiter's pace is guessed eventually (docs/SPEC.md § 8 row 22, review S6). Only a wrong
 * credential counts: a refusal because the account is already locked (or disabled, or throttled) must not touch
 * the counter, otherwise retrying would extend the lock without end, which is a way to keep someone out of
 * their own account.
 *
 * The sign-in never says an account is locked, which would say the address is real: its owner is told by mail, once,
 * by the guess that sets the lock, never by the ones refused while it holds, so a lock is no way to flood a mailbox.
 */
final readonly class RecordFailedLogin implements DeclaresAuditActions
{
    public function __construct(
        private UserRepository $users,
        private AuditTrail $audit,
        private SecurityNotices $notices,
        private ClockInterface $clock,
        #[Autowire(param: 'app.auth.lock_after_failures')]
        private int $lockAfterFailures,
        #[Autowire(param: 'app.auth.lock_duration')]
        private string $lockDuration,
    ) {
    }

    public function handle(FailedLoginAttempt $attempt): void
    {
        $user = null === $attempt->userId ? null : $this->users->ofId($attempt->userId);
        $changes = ['email' => $attempt->email, 'reason' => $attempt->reason];

        if (null !== $user && $attempt->wrongCredential) {
            $now = $this->clock->now();
            $locks = $user->recordFailedLogin($now, $this->lockAfterFailures, new \DateInterval($this->lockDuration));
            $this->users->save($user);
            $changes['failed_login_count'] = $user->getFailedLoginCount();
            $changes['locked'] = $user->isLockedAt($now);
            if ($locks) {
                $this->notices->tell($user->getId(), SecurityChange::AccountLocked, until: $user->getLockedUntil());
            }
        }

        $this->audit->record(new AuditEntry(LoginAudit::ENTITY_TYPE, $user?->getId(), LoginAudit::LOGIN_FAILED, null, $changes));
    }

    public function auditActions(): array
    {
        return [
            LoginAudit::LOGIN_FAILED => new Recorded(LoginAudit::ENTITY_TYPE, AuditScope::Outside),
        ];
    }
}

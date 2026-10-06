<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Password;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Application\Login\PasswordAttempts;
use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A signed-in person replaces their password, proving they know the current one. The new one is at least twelve
 * characters, not the current one, and not one that has appeared in a breach (an unreachable breach service accepts it
 * and says so in the audit, as at signup). Every session opened with the old password ends, this one included: the
 * person signs in again with the new one. A refusal is audited too, in its own transaction, so it outlives the throw.
 */
final readonly class ChangePassword
{
    public const string CHANGED = 'auth.password_changed';
    public const string REFUSED = 'auth.password_change_refused';
    public const string BREACH_CHECK_SKIPPED = 'auth.password_breach_check_skipped';

    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private PasswordAttempts $passwords,
        private NewPasswordPolicy $policy,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** @throws NewPasswordRefused */
    public function handle(Uuid $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->users->ofId($userId);
        if (null === $user || !$this->passwords->matches($user, $currentPassword, 'password_change')) {
            throw $this->refuse($userId, NewPasswordRefused::CURRENT_PASSWORD);
        }

        if ($newPassword === $currentPassword) {
            throw $this->refuse($userId, NewPasswordRefused::UNCHANGED);
        }

        // Asked before the transaction opens: the breach check is a call to another service.
        try {
            $breached = $this->policy->check($newPassword);
        } catch (NewPasswordRefused $refused) {
            throw $this->refuse($userId, $refused->reason);
        }

        $this->transactions->run(function () use ($user, $newPassword, $breached): void {
            $user->setPasswordHash($this->hasher->hash($newPassword), $this->clock->now());
            $this->users->save($user);
            if (null === $breached) {
                $this->audit->record(new AuditEntry('user', $user->getId(), self::BREACH_CHECK_SKIPPED, $user->getId(), ['reason' => 'the breach service could not be reached']));
            }
            $this->audit->record(new AuditEntry('user', $user->getId(), self::CHANGED, $user->getId()));
        });
    }

    private function refuse(Uuid $userId, string $reason): NewPasswordRefused
    {
        $this->transactions->run(fn () => $this->audit->record(new AuditEntry('user', $userId, self::REFUSED, $userId, ['reason' => $reason])));

        return new NewPasswordRefused($reason);
    }
}

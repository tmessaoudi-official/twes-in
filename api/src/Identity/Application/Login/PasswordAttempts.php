<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\User;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;

/**
 * The account's password asked again inside a session, at a step-up or a password change: a wrong one is a guess at the
 * same account as a wrong one at the sign-in, so it counts toward the same lockout (audit 2026-10-06, D-4), and a
 * locked account is answered no without its password being compared. Otherwise an open session would be a way to guess
 * the password at the limiter's pace, all day.
 */
final readonly class PasswordAttempts
{
    public function __construct(
        private PasswordHasher $hasher,
        private RecordFailedLogin $failures,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    public function matches(User $user, string $password, string $reason): bool
    {
        if ($user->isLockedAt($this->clock->now())) {
            return false;
        }
        if ('' !== $password && $this->hasher->verify($user->getPasswordHash(), $password)) {
            return true;
        }
        // The count and its audit row are stored together.
        $this->transactions->run(fn () => $this->failures->handle(new FailedLoginAttempt($user->getId(), $user->getEmail()->value, $reason, true)));

        return false;
    }
}

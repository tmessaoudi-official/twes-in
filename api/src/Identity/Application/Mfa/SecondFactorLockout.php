<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Identity\Application\Login\FailedLoginAttempt;
use App\Identity\Application\Login\RecordFailedLogin;
use App\Identity\Domain\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The account lockout as the second factor sees it.
 *
 * A rate limiter paces guesses, it never stops them: six digits with a step of leeway is three codes in a
 * million, which a patient attacker holding the password reaches in a year of the limiter's own budget. So a
 * wrong code counts towards the same lockout a wrong password does, and a locked account is refused at the
 * place the guess is made — `UserChecker` never runs on these endpoints, because by design no session exists
 * yet (docs/SPEC.md § 8 row 22, review S6).
 */
final readonly class SecondFactorLockout
{
    /** What the audit row calls a wrong code, beside `BadCredentialsException` for a wrong password. */
    public const string WRONG_CODE = 'wrong_second_factor';

    public function __construct(
        private UserRepository $users,
        private RecordFailedLogin $recordFailedLogin,
        private ClockInterface $clock,
    ) {
    }

    /** Whether this account is locked right now. An account that no longer exists is not locked; it simply fails later. */
    public function locked(Uuid $userId): bool
    {
        $user = $this->users->ofId($userId);

        return null !== $user && $user->isLockedAt($this->clock->now());
    }

    /**
     * One guess spent. The email is left out: the pending marker holds an id and nothing else, and the audit row
     * points at the user anyway.
     */
    public function recordWrongCode(Uuid $userId): void
    {
        $this->recordFailedLogin->handle(new FailedLoginAttempt($userId, null, self::WRONG_CODE, wrongCredential: true));
    }
}

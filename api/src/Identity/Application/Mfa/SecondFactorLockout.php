<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Identity\Application\Login\FailedLoginAttempt;
use App\Identity\Application\Login\RecordFailedLogin;
use App\Identity\Domain\Repository\UserRepository;
use App\Identity\Domain\ValueObject\RecoveryCode;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The password's lock as the second factor sees it.
 *
 * A rate limiter paces guesses, it never stops them: six digits with a step of leeway is three codes in a
 * million, which a patient attacker holding the password reaches in a year of the limiter's own budget. So a
 * wrong code counts towards the same lock a wrong password does, and a six-digit code is refused on it at the
 * place the guess is made, since no session exists there yet to be checked (review S6). A passkey cannot be
 * guessed, so it meets no lock, and a recovery code gets past it (`refuses`).
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

    /**
     * Whether the lock refuses this code at the second step. A recovery code still gets past a locked password: ten
     * characters of a 31-letter alphabet are out of a guesser's reach, unlike the million six-digit codes the lock is
     * there for, and the owner whose own slips set it needs a way in.
     */
    public function refuses(Uuid $userId, string $code): bool
    {
        return !RecoveryCode::isShaped($code) && $this->locked($userId);
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

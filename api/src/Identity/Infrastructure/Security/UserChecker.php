<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A locked account is refused BEFORE its password is compared, as a wrong password is: told apart, the right password
 * would answer differently from a wrong one, and the lock would tell a guesser which guess was right; told as locked,
 * both would say the address is real. Its owner hears of the lock by mail instead (`RecordFailedLogin`).
 *
 * A deactivated account is refused once the password is known to be right (`AccountStateAfterPassword`), so a wrong
 * one still answers as an unknown address does. Post-auth checks both again; by then the password was right, so
 * `account_locked` there tells only someone who already holds it. The message keys are what the failure handler
 * returns to the client.
 */
final readonly class UserChecker implements UserCheckerInterface
{
    public const string ACCOUNT_LOCKED = 'account_locked';
    public const string ACCOUNT_DISABLED = 'account_disabled';

    public function __construct(private ClockInterface $clock)
    {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof SecurityUser && $user->isLockedAt($this->clock->now())) {
            throw new LockedAccountRefused();
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof SecurityUser) {
            return;
        }
        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException(self::ACCOUNT_DISABLED);
        }
        if ($user->isLockedAt($this->clock->now())) {
            throw new CustomUserMessageAccountStatusException(self::ACCOUNT_LOCKED);
        }
    }
}

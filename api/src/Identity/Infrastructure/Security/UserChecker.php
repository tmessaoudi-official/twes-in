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
 * Refuses locked and deactivated accounts before the password is even compared (pre-auth), and on every
 * later request (post-auth, through the context listener). The message keys are what the failure handler
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

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->checkPreAuth($user);
    }
}

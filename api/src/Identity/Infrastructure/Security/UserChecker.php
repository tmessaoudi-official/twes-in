<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A deactivated account is refused once the password is known to be right (`AccountStateAfterPassword`), so a wrong one
 * still answers as an unknown address does. Post-auth checks it again, on every way in. The message key is what the
 * failure handler returns to the client.
 *
 * The lock is not checked here: it is the password's, refused before the password is compared
 * (`RefuseALockedPassword`), while a passkey or a recovery code still signs in on it, and every second step ends in
 * `Security::login`, which runs this checker.
 */
final readonly class UserChecker implements UserCheckerInterface
{
    public const string ACCOUNT_DISABLED = 'account_disabled';

    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof SecurityUser && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException(self::ACCOUNT_DISABLED);
        }
    }
}

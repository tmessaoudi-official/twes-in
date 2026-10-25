<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * A sign-in refused because the account is locked, before its password is compared. Answered as a wrong password is
 * (`LoginFailureHandler`), and not a bad credential, so it never counts toward the lock (`AuthenticationEventsListener`).
 * A plain authentication exception rather than an account status one, which Symfony may turn into a bad credential.
 */
final class LockedAccountRefused extends AuthenticationException
{
    public function getMessageKey(): string
    {
        return 'Invalid credentials.';
    }
}

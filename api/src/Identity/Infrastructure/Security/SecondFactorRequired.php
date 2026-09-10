<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Not a failure: the password was right and one step is left.
 *
 * It is an `AuthenticationException` because that is what stops Symfony creating the token — which is the
 * whole design. A password-only session is refused everywhere because it never exists, not because each
 * endpoint remembered to look (ruling of 2026-09-10).
 */
final class SecondFactorRequired extends AuthenticationException
{
    public function getMessageKey(): string
    {
        return 'mfa_required';
    }
}

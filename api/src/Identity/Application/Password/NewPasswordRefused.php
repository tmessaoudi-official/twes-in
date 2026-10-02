<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Password;

/** A password change that did not go through, and why; the screen says it in the person's words. */
final class NewPasswordRefused extends \DomainException
{
    public const string CURRENT_PASSWORD = 'current_password';
    public const string TOO_SHORT = 'too_short';
    public const string UNCHANGED = 'unchanged';
    public const string BREACHED = 'breached';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(\sprintf('The password was not changed: %s.', $reason));
    }
}

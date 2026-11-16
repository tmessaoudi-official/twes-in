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

    /** @param int|null $min the length a new password needs, said with a refusal for being too short */
    public function __construct(public readonly string $reason, public readonly ?int $min = null)
    {
        parent::__construct(\sprintf('The password was not changed: %s.', $reason));
    }
}

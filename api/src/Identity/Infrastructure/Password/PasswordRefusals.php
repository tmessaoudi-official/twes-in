<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Password;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Identity\Application\Password\NewPasswordRefused;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;

/**
 * A new password refused, as the change and the reset screens read it: a violation on the field the person typed it
 * in, `currentPassword` for a wrong current password, `newPassword` for every reason of the new one.
 */
final class PasswordRefusals
{
    public const string CURRENT = 'currentPassword';
    public const string NEW = 'newPassword';

    public static function of(NewPasswordRefused $refused): ValidationException
    {
        $field = NewPasswordRefused::CURRENT_PASSWORD === $refused->reason ? self::CURRENT : self::NEW;

        return DomainRule::refused($field, $refused->getMessage(), $refused->reason, previous: $refused);
    }
}

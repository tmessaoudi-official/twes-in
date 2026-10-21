<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

/**
 * A link's token is a password for as long as it lives: an invitation, a sign-up, a password reset, a mail's stop link,
 * each 64 hexadecimal characters in an address's path. A log line or an error report keeps where a request went, never
 * that token: any run of 32 hexadecimal characters or more is taken for one, which no identifier written here is (a
 * UUID's longest run is 12).
 */
final class SecretTokens
{
    private const string PATTERN = '/(?<![0-9a-f])[0-9a-f]{32,}(?![0-9a-f])/i';

    /** The text with every token in it written `[token]`. */
    public static function mask(string $text): string
    {
        return preg_replace(self::PATTERN, '[token]', $text) ?? '[unreadable]';
    }
}

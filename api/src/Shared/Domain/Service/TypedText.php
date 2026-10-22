<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\Service;

/**
 * What a person typed or pasted, without what sits at its ends: PHP's `trim()` takes the ASCII spaces only, and a paste
 * from a web page, a spreadsheet or an Arabic keyboard brings no-break and zero-width spaces, byte-order marks and
 * direction marks nobody sees, so a reference, a number or a name so written would be another one. Every space
 * (`\p{Z}`) and every invisible format character (`\p{Cf}`) goes, and what sits between the ends stays as typed.
 * `scripts/gates/typed-trims.sh` holds every one-argument `trim()` in `api/src` to this.
 */
final class TypedText
{
    private const string ENDS = '/^[\s\x00\p{Z}\p{Cf}]+|[\s\x00\p{Z}\p{Cf}]+$/u';

    public static function trimmed(string $value): string
    {
        // Bytes that are no UTF-8 are kept for the encoding's own refusal, which names them.
        if (!mb_check_encoding($value, 'UTF-8')) {
            return trim($value); // trim: the value is no UTF-8, which the pattern cannot read
        }

        return (string) preg_replace(self::ENDS, '', $value);
    }

    /** Null for nothing at all, as an optional field leaves it. */
    public static function trimmedOrNull(?string $value): ?string
    {
        $value = null === $value ? '' : self::trimmed($value);

        return '' === $value ? null : $value;
    }
}

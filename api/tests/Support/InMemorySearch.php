<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

/** How the in-memory repositories match a list's words: as the database does, except for accents. */
final class InMemorySearch
{
    /** @param list<string|null> $texts */
    public static function finds(?string $words, string $number, array $texts): bool
    {
        $words = mb_strtolower(trim($words ?? ''));

        return match (true) {
            '' === $words => true,
            mb_strlen($words) < 3 => mb_strtolower($number) === $words,
            default => str_contains(mb_strtolower(implode(' ', [$number, ...$texts])), $words),
        };
    }
}

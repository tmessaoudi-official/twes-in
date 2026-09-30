<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures\Scale;

/**
 * What a raw query returns is `mixed`. These read a value as what the query names it, and refuse anything else with
 * a message, rather than let a cast turn a missing column into an empty string or a zero.
 */
final class Rows
{
    public static function text(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value) => (string) $value,
            default => throw new \UnexpectedValueException('A column the query names came back empty or of another type.'),
        };
    }

    public static function int(mixed $value): int
    {
        return match (true) {
            \is_int($value) => $value,
            \is_string($value) && 1 === preg_match('/^-?\d+$/', $value) => (int) $value,
            default => throw new \UnexpectedValueException('A count the query names did not come back as a whole number.'),
        };
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    public static function texts(array $values): array
    {
        return array_map(self::text(...), $values);
    }
}

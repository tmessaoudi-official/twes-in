<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

/**
 * What a raw query returns is `mixed`. These read a value as what the query names it, and refuse anything else with
 * a message, rather than let a cast turn a missing column into an empty string or a zero: a fallback shows a renamed
 * column or a corrupt value as a plausible figure nobody questions. `scripts/gates/row-defaults.sh` finds the fallback.
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

    /** @return array<array-key, mixed> */
    public static function row(mixed $row): array
    {
        return \is_array($row) ? $row : throw new \UnexpectedValueException('A row the query returns did not come back as one.');
    }

    /** A column the schema lets be empty: null is its own answer, anything but text still refused. */
    public static function textOrNull(mixed $value): ?string
    {
        return null === $value ? null : self::text($value);
    }

    public static function int(mixed $value): int
    {
        return match (true) {
            \is_int($value) => $value,
            \is_string($value) && 1 === preg_match('/^-?\d+$/', $value) => (int) $value,
            default => throw new \UnexpectedValueException('A count the query names did not come back as a whole number.'),
        };
    }

    public static function bool(mixed $value): bool
    {
        return \is_bool($value) ? $value : throw new \UnexpectedValueException('A flag the query names did not come back as true or false.');
    }

    /**
     * A database sum, as the database wrote it: no row sums to null, which is nothing; a float is refused, since money
     * and quantities come back as decimal text.
     *
     * @return numeric-string
     */
    public static function sum(mixed $value): string
    {
        return match (true) {
            null === $value => '0',
            \is_int($value) => (string) $value,
            \is_string($value) && is_numeric($value) => $value,
            default => throw new \UnexpectedValueException('A sum the query names did not come back as a number.'),
        };
    }

    /**
     * What an ORM query hands back, read as the entities it selects: fetch-joined aliases ride on them, so anything
     * else in the list is a query that changed, refused rather than filtered out of sight.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public static function entities(mixed $found, string $class): array
    {
        if (!\is_array($found)) {
            throw new \UnexpectedValueException(\sprintf('A query for %s did not come back as a list.', $class));
        }
        $entities = [];
        foreach ($found as $each) {
            $entities[] = $each instanceof $class ? $each : throw new \UnexpectedValueException(\sprintf('A query for %s came back holding %s.', $class, get_debug_type($each)));
        }

        return $entities;
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

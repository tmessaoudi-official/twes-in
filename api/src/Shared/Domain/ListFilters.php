<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * What a list's filters say, read from its query string (docs/SPEC.md § 7, 2026-10-06): a filter takes several values,
 * `status[]=a&status[]=b`, and the single `status=a` is still the same filter with one; an interval is `issueDate[from]`
 * and `[to]`, or `total[min]` and `[max]`, each end inclusive. The values of one filter are OR'd and different filters
 * are AND'd, which the list's query does with what is read here. A value that is none of the filter's is refused, never
 * dropped: a screen that sent it is wrong, and a silently wider list would hide that.
 */
final readonly class ListFilters
{
    private const string DAY = '/^\d{4}-\d{2}-\d{2}$/';
    private const string DECIMAL = '/^(0|[1-9]\d{0,10})(\.\d{1,4})?$/';

    /** @param array<array-key, mixed> $parameters the query string, as the request parsed it */
    public function __construct(private array $parameters = [])
    {
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string> the distinct values named, in the order given
     *
     * @throws InvalidFilter
     */
    public function choices(string $key, array $allowed): array
    {
        $values = [];
        foreach ($this->values($key) as $value) {
            \in_array($value, $allowed, true) || throw new InvalidFilter($key, \sprintf('"%s" is not one of %s.', $value, implode(', ', $allowed)));
            $values[$value] = $value;
        }

        return array_values($values);
    }

    /**
     * Values whose set is open but whose shape is known, such as a country's code.
     *
     * @return list<string> the distinct values named, in the order given
     *
     * @throws InvalidFilter
     */
    public function matching(string $key, string $pattern): array
    {
        $values = [];
        foreach ($this->values($key) as $value) {
            1 === preg_match($pattern, $value) || throw new InvalidFilter($key, \sprintf('"%s" is not of the expected shape.', $value));
            $values[$value] = $value;
        }

        return array_values($values);
    }

    /**
     * @return list<Uuid>
     *
     * @throws InvalidFilter
     */
    public function uuids(string $key): array
    {
        $ids = [];
        foreach ($this->values($key) as $value) {
            Uuid::isValid($value) || throw new InvalidFilter($key, \sprintf('"%s" is not an identifier.', $value));
            $ids[strtolower($value)] = Uuid::fromString($value);
        }

        return array_values($ids);
    }

    /** @throws InvalidFilter */
    public function dateRange(string $key): DateRange
    {
        [$from, $to] = $this->ends($key, 'from', 'to');
        foreach (['from' => $from, 'to' => $to] as $end => $day) {
            if (null !== $day && !self::isDay($day)) {
                throw new InvalidFilter(\sprintf('%s[%s]', $key, $end), \sprintf('"%s" is not a day, YYYY-MM-DD.', $day));
            }
        }
        if (null !== $from && null !== $to && $from > $to) {
            throw new InvalidFilter($key, 'the interval ends before it starts.');
        }

        return new DateRange($from, $to);
    }

    /** @throws InvalidFilter */
    public function decimalRange(string $key): DecimalRange
    {
        [$min, $max] = $this->ends($key, 'min', 'max');
        foreach (['min' => $min, 'max' => $max] as $end => $amount) {
            if (null !== $amount && 1 !== preg_match(self::DECIMAL, $amount)) {
                throw new InvalidFilter(\sprintf('%s[%s]', $key, $end), \sprintf('"%s" is not an amount.', $amount));
            }
        }
        if (null !== $min && null !== $max && is_numeric($min) && is_numeric($max) && bccomp($min, $max, 4) > 0) {
            throw new InvalidFilter($key, 'the interval ends before it starts.');
        }

        return new DecimalRange($min, $max);
    }

    /** @return list<string> the non-empty values one key carries, whether it came as `key=a` or `key[]=a` */
    private function values(string $key): array
    {
        $given = $this->parameters[$key] ?? null;
        $given = \is_array($given) ? $given : [$given];
        $values = [];
        foreach ($given as $value) {
            if (\is_string($value)) {
                '' === trim($value) || $values[] = trim($value);
            } elseif (null !== $value) {
                throw new InvalidFilter($key, 'a value is expected, not a structure.');
            }
        }

        return $values;
    }

    /** @return array{?string, ?string} */
    private function ends(string $key, string $low, string $high): array
    {
        $given = $this->parameters[$key] ?? null;
        if (null === $given) {
            return [null, null];
        }
        \is_array($given) || throw new InvalidFilter($key, \sprintf('an interval is given as %1$s[%2$s] and %1$s[%3$s].', $key, $low, $high));
        $read = static function (mixed $value) use ($key): ?string {
            if (null === $value || '' === $value) {
                return null;
            }

            return \is_string($value) ? trim($value) : throw new InvalidFilter($key, 'an end of an interval is a single value.');
        };

        return [$read($given[$low] ?? null), $read($given[$high] ?? null)];
    }

    private static function isDay(string $day): bool
    {
        if (1 !== preg_match(self::DAY, $day)) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return false !== $parsed && $parsed->format('Y-m-d') === $day;
    }
}

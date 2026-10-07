<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\DateRange;
use App\Shared\Domain\DecimalRange;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

/**
 * An interval filter as the predicate a list's query adds (docs/SPEC.md § 7, 2026-10-06): each end inclusive, either end
 * open, a row with nothing in the column left out. A plain comparison of the column with a bound, so an index on the
 * column answers it; the parameter names carry the caller's prefix, so two intervals on one query never collide.
 */
final class Intervals
{
    public static function days(QueryBuilder $query, string $column, string $name, ?DateRange $range): void
    {
        if (null === $range) {
            return;
        }
        if (null !== $range->from) {
            $query->andWhere("$column >= :{$name}_from")->setParameter("{$name}_from", new \DateTimeImmutable($range->from), Types::DATE_IMMUTABLE);
        }
        if (null !== $range->to) {
            $query->andWhere("$column <= :{$name}_to")->setParameter("{$name}_to", new \DateTimeImmutable($range->to), Types::DATE_IMMUTABLE);
        }
    }

    /**
     * An interval of days over a moment column, stored in UTC: each day is the company's own, from its first instant to
     * the first instant of the next, so a movement at 00:30 in Tunis belongs to that day and not to the one before.
     */
    public static function moments(QueryBuilder $query, string $column, string $name, ?DateRange $range, string $timezone): void
    {
        if (null === $range) {
            return;
        }
        $zone = new \DateTimeZone($timezone);
        $utc = new \DateTimeZone('UTC');
        if (null !== $range->from) {
            $query->andWhere("$column >= :{$name}_from")
                ->setParameter("{$name}_from", new \DateTimeImmutable($range->from, $zone)->setTimezone($utc), Types::DATETIME_IMMUTABLE);
        }
        if (null !== $range->to) {
            $query->andWhere("$column < :{$name}_before")
                ->setParameter("{$name}_before", new \DateTimeImmutable($range->to, $zone)->modify('+1 day')->setTimezone($utc), Types::DATETIME_IMMUTABLE);
        }
    }

    public static function amounts(QueryBuilder $query, string $column, string $name, ?DecimalRange $range): void
    {
        if (null === $range) {
            return;
        }
        if (null !== $range->min) {
            $query->andWhere("$column >= :{$name}_min")->setParameter("{$name}_min", $range->min, ParameterType::STRING);
        }
        if (null !== $range->max) {
            $query->andWhere("$column <= :{$name}_max")->setParameter("{$name}_max", $range->max, ParameterType::STRING);
        }
    }

    /**
     * An amount interval over the size of a signed figure: a credit note's total is negative, and « from 100 » among
     * invoices and credit notes means its size. The column's own index does not answer it; the list is narrowed to
     * its company first.
     */
    public static function sizes(QueryBuilder $query, string $column, string $name, ?DecimalRange $range): void
    {
        self::amounts($query, "ABS($column)", $name, $range);
    }
}

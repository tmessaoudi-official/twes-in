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
}

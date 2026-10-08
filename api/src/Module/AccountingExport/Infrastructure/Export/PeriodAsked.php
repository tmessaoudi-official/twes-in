<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Infrastructure\Export;

use App\ImportExport\Application\ExportQuery;
use App\Module\AccountingExport\Application\JournalPeriod;
use App\Shared\Domain\InvalidFilter;

/** The period a file asks for, `from` and `to`, each a day written YYYY-MM-DD; both are required. */
final class PeriodAsked
{
    /** @throws InvalidFilter */
    public static function of(ExportQuery $query): JournalPeriod
    {
        return new JournalPeriod(self::day($query, 'from'), self::day($query, 'to'));
    }

    private static function day(ExportQuery $query, string $key): \DateTimeImmutable
    {
        $written = $query->text($key) ?? throw new InvalidFilter($key, 'The period names its first and last day.');
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $written, new \DateTimeZone('UTC'));
        // A day the calendar does not have rolls over rather than failing: compare it with what was written.
        if (false === $day || $day->format('Y-m-d') !== $written) {
            throw new InvalidFilter($key, 'A day is written YYYY-MM-DD.');
        }

        return $day;
    }
}

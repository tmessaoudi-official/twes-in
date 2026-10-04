<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * The month so far and the same number of days of the month before, as day ranges with the end excluded (what a home
 * figure is compared over). The month before may be shorter than the days gone by: its range then stops where this
 * month starts rather than reading into it.
 */
final readonly class MonthSoFar
{
    private function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $until,
        public \DateTimeImmutable $previousFrom,
        public \DateTimeImmutable $previousUntil,
    ) {
    }

    /** @param \DateTimeImmutable $today the company's own day, at midnight */
    public static function of(\DateTimeImmutable $today): self
    {
        $from = $today->modify('first day of this month');
        $previousFrom = $from->modify('-1 month');

        return new self(
            $from,
            $today->modify('+1 day'),
            $previousFrom,
            min($previousFrom->modify(\sprintf('+%d days', (int) $today->format('j'))), $from),
        );
    }
}

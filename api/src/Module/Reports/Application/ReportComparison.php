<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

/**
 * What a report is read against (comparison is a parameter, not a separate report):
 * nothing, the period before, or the same days a year earlier.
 */
enum ReportComparison: string
{
    case None = 'none';
    case Previous = 'previous';
    case Year = 'year';

    /**
     * The period read beside the one asked, or null for none. A period of whole months from the first of a month is read
     * against as many months before it, the first to the eighteenth against the first to the eighteenth of the month
     * before: what « this month against last month » means to a shop, the home's own comparison. Any other period is read
     * against as many days just before it. A day past the end of a shorter month is that month's last day, and a period
     * that ends on its month's last day is read to the last day of the month it is compared with.
     */
    public function of(ReportPeriod $period): ?ReportPeriod
    {
        return match ($this) {
            self::None => null,
            self::Year => new ReportPeriod(self::monthsBack($period->from, 12, false), self::monthsBack($period->to, 12, self::endsMonth($period->to))),
            self::Previous => self::previous($period),
        };
    }

    private static function previous(ReportPeriod $period): ReportPeriod
    {
        if ('01' === $period->from->format('d')) {
            $months = ((int) $period->to->format('Y') - (int) $period->from->format('Y')) * 12 + (int) $period->to->format('n') - (int) $period->from->format('n') + 1;

            return new ReportPeriod(self::monthsBack($period->from, $months, false), self::monthsBack($period->to, $months, self::endsMonth($period->to)));
        }
        $days = (int) $period->from->diff($period->to)->days + 1;

        return new ReportPeriod($period->from->modify(\sprintf('-%d days', $days)), $period->from->modify('-1 day'));
    }

    private static function endsMonth(\DateTimeImmutable $day): bool
    {
        return $day->format('d') === $day->format('t');
    }

    /** The same day so many months before, or that month's last day when it has no such day or when `toMonthEnd`. */
    private static function monthsBack(\DateTimeImmutable $day, int $months, bool $toMonthEnd): \DateTimeImmutable
    {
        $first = $day->modify('first day of this month')->modify(\sprintf('-%d months', $months));
        $last = (int) $first->format('t');
        $date = $toMonthEnd ? $last : min((int) $day->format('j'), $last);

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $date);
    }
}

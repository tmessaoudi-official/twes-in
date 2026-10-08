<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Domain;

/** How often a recurring invoice is drafted again: a week, or a number of whole months kept on the first day's date. */
enum RecurringFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    /**
     * The day of the `$index`-th occurrence counted from the first, which is index 0. A month keeps the first day's date
     * and falls back to the month's last day where it has none: the 31st of January, then the 28th or 29th of February,
     * then the 31st of March again.
     */
    public function occurrence(\DateTimeImmutable $first, int $index): \DateTimeImmutable
    {
        if (self::Weekly === $this) {
            return $first->modify(\sprintf('+%d days', 7 * $index));
        }
        $months = $index * match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
        };
        $month = $first->modify('first day of this month')->modify(\sprintf('+%d months', $months));
        $day = min((int) $first->format('j'), (int) $month->format('t'));

        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day);
    }
}

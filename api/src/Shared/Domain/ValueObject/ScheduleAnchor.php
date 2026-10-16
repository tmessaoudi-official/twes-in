<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/** The day of its month an occurrence counted in months or years falls on. */
enum ScheduleAnchor: string
{
    /** A date, kept, and the month's last day where the month is shorter. */
    case Date = 'date';
    case LastDay = 'last_day';
    /** The first to the fourth of a day of the week. */
    case Weekday = 'weekday';
    case LastWeekday = 'last_weekday';
}

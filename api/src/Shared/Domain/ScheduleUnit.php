<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/** What a schedule counts its interval in. */
enum ScheduleUnit: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    /** A month or a year falls on the day it is anchored to; a day or a week counts from the first day. */
    public function isAnchored(): bool
    {
        return self::Month === $this || self::Year === $this;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain;

/** The unit a billing period is counted in. */
enum PeriodUnit: string
{
    case Day = 'day';
    case Month = 'month';
    case Year = 'year';
}

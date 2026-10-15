<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

/** A resource that writes a schedule flat: every so many units, a month or a year on the day it is anchored to. */
interface ScheduleRequest
{
    public string $unit { get; }

    public int $every { get; }

    public ?string $anchor { get; }

    public ?int $monthDay { get; }

    public ?int $ordinal { get; }

    public ?int $weekday { get; }
}

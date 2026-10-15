<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Infrastructure\ApiPlatform;

use App\Shared\Domain\InvalidSchedule;
use App\Shared\Domain\Schedule;
use App\Shared\Domain\ScheduleAnchor;
use App\Shared\Domain\ScheduleUnit;

/**
 * The schedule and the days a request wrote, read once the resource's constraints passed: the unit and the anchor are
 * then among their cases and each day a real calendar day.
 */
final class RequestedSchedule
{
    /** @throws InvalidSchedule */
    public static function of(RecurringExpenseResource $data): Schedule
    {
        return new Schedule(
            ScheduleUnit::from($data->unit),
            $data->every,
            null === $data->anchor ? null : ScheduleAnchor::from($data->anchor),
            $data->monthDay,
            $data->ordinal,
            $data->weekday,
        );
    }

    public static function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('UTC'));
    }

    public static function dayOrNull(?string $day): ?\DateTimeImmutable
    {
        return null === $day ? null : self::day($day);
    }
}

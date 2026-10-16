<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Exception\InvalidSchedule;
use App\Shared\Domain\Model\Schedule;
use App\Shared\Domain\ValueObject\ScheduleAnchor;
use App\Shared\Domain\ValueObject\ScheduleUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A schedule's occurrences: every so many days, weeks, months or years, a month or a year falling on the day it is
 * anchored to — a date kept and clamped to shorter months, the month's last day, its nth weekday or its last one —
 * and never before the first day.
 */
final class ScheduleTest extends TestCase
{
    /** @return iterable<string, array{Schedule, string, int, string}> */
    public static function occurrences(): iterable
    {
        yield 'every three days' => [new Schedule(ScheduleUnit::Day, 3), '2026-10-08', 2, '2026-10-14'];
        yield 'every two weeks' => [new Schedule(ScheduleUnit::Week, 2), '2026-10-08', 3, '2026-11-19'];
        yield 'a month' => [self::onDate(ScheduleUnit::Month, 1, 8), '2026-10-08', 1, '2026-11-08'];
        yield 'the 31st in February' => [self::onDate(ScheduleUnit::Month, 1, 31), '2027-01-31', 1, '2027-02-28'];
        yield 'the 31st again in March' => [self::onDate(ScheduleUnit::Month, 1, 31), '2027-01-31', 2, '2027-03-31'];
        yield 'a leap year' => [self::onDate(ScheduleUnit::Month, 1, 30), '2028-01-30', 1, '2028-02-29'];
        yield 'a quarter' => [self::onDate(ScheduleUnit::Month, 3, 30), '2026-11-30', 1, '2027-02-28'];
        yield 'a year from the 29th of February' => [self::onDate(ScheduleUnit::Year, 1, 29), '2028-02-29', 1, '2029-02-28'];
        yield 'the first is the first day' => [self::onDate(ScheduleUnit::Year, 1, 8), '2026-10-08', 0, '2026-10-08'];
        yield 'a date later in the first month' => [self::onDate(ScheduleUnit::Month, 1, 20), '2026-10-08', 0, '2026-10-20'];
        yield 'a date already past in the first month' => [self::onDate(ScheduleUnit::Month, 3, 5), '2026-10-20', 0, '2027-01-05'];
        yield 'the last day' => [new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::LastDay), '2026-10-08', 0, '2026-10-31'];
        yield 'the last day in February' => [new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::LastDay), '2026-10-08', 4, '2027-02-28'];
        yield 'the second Tuesday' => [new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::Weekday, ordinal: 2, weekday: 2), '2026-10-08', 0, '2026-10-13'];
        yield 'the second Tuesday a month on' => [new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::Weekday, ordinal: 2, weekday: 2), '2026-10-08', 1, '2026-11-10'];
        yield 'the last Friday' => [new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::LastWeekday, weekday: 5), '2026-10-08', 0, '2026-10-30'];
        yield 'the last Friday a month on' => [new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::LastWeekday, weekday: 5), '2026-10-08', 1, '2026-11-27'];
        yield 'the fourth Thursday a year on' => [new Schedule(ScheduleUnit::Year, 1, ScheduleAnchor::Weekday, ordinal: 4, weekday: 4), '2026-11-01', 1, '2027-11-25'];
        yield 'a second Tuesday already past starts a period on' => [new Schedule(ScheduleUnit::Month, 2, ScheduleAnchor::Weekday, ordinal: 2, weekday: 2), '2026-10-20', 0, '2026-12-08'];
    }

    #[DataProvider('occurrences')]
    public function testAnOccurrenceFallsOnItsAnchor(Schedule $schedule, string $first, int $index, string $day): void
    {
        self::assertSame($day, $schedule->occurrence(new \DateTimeImmutable($first), $index)->format('Y-m-d'));
    }

    public function testADayIsReadAsWrittenWhateverItsZone(): void
    {
        $first = new \DateTimeImmutable('2026-10-08 00:10', new \DateTimeZone('Africa/Tunis'));

        self::assertSame('2026-10-08 00:00 UTC', (new Schedule(ScheduleUnit::Day, 1))->occurrence($first, 0)->format('Y-m-d H:i T'));
    }

    /** @return iterable<string, array{\Closure(): Schedule, string}> */
    public static function refusals(): iterable
    {
        yield 'never' => [static fn () => new Schedule(ScheduleUnit::Day, 0), 'every'];
        yield 'a hundred apart' => [static fn () => new Schedule(ScheduleUnit::Week, 100), 'every'];
        yield 'a day with an anchor' => [static fn () => new Schedule(ScheduleUnit::Day, 1, ScheduleAnchor::LastDay), 'anchor'];
        yield 'a month without one' => [static fn () => new Schedule(ScheduleUnit::Month, 1), 'anchor'];
        yield 'a date without its day' => [static fn () => new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::Date), 'monthDay'];
        yield 'the 32nd' => [static fn () => self::onDate(ScheduleUnit::Month, 1, 32), 'monthDay'];
        yield 'the 0th' => [static fn () => self::onDate(ScheduleUnit::Month, 1, 0), 'monthDay'];
        yield 'a fifth Monday' => [static fn () => new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::Weekday, ordinal: 5, weekday: 1), 'ordinal'];
        yield 'an nth weekday without its rank' => [static fn () => new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::Weekday, weekday: 1), 'ordinal'];
        yield 'an eighth day of the week' => [static fn () => new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::LastWeekday, weekday: 8), 'weekday'];
        yield 'a last weekday without its day' => [static fn () => new Schedule(ScheduleUnit::Year, 1, ScheduleAnchor::LastWeekday), 'weekday'];
    }

    /** @param \Closure(): Schedule $make */
    #[DataProvider('refusals')]
    public function testAScheduleThatCannotRecurIsRefusedOnItsField(\Closure $make, string $field): void
    {
        try {
            $make();
            self::fail("$field was taken");
        } catch (InvalidSchedule $refused) {
            self::assertSame([$field, 'invalid_value'], [$refused->field, $refused->reason]);
        }
    }

    public function testWhatItsAnchorDoesNotReadIsLeftOutSoTwoEqualSchedulesCompareEqual(): void
    {
        $typed = new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::LastDay, monthDay: 12, ordinal: 2, weekday: 3);

        self::assertSame([null, null, null], [$typed->monthDay, $typed->ordinal, $typed->weekday]);
        self::assertTrue($typed->equals(new Schedule(ScheduleUnit::Month, 1, ScheduleAnchor::LastDay)));
        self::assertFalse($typed->equals(new Schedule(ScheduleUnit::Month, 2, ScheduleAnchor::LastDay)));
        self::assertFalse(self::onDate(ScheduleUnit::Month, 1, 12)->equals(self::onDate(ScheduleUnit::Month, 1, 13)));
    }

    private static function onDate(ScheduleUnit $unit, int $every, int $day): Schedule
    {
        return new Schedule($unit, $every, ScheduleAnchor::Date, monthDay: $day);
    }
}

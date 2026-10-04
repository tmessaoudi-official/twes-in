<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use App\Shared\Application\MonthSoFar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonthSoFarTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string}> today, then this month's window and last month's */
    public static function days(): iterable
    {
        yield 'an ordinary day' => ['2026-09-21', '2026-09-01', '2026-09-22', '2026-08-01', '2026-08-22'];
        yield 'the first of the month is one day against one day' => ['2026-09-01', '2026-09-01', '2026-09-02', '2026-08-01', '2026-08-02'];
        yield 'the 31st against a shorter month stops where this month starts' => ['2026-03-31', '2026-03-01', '2026-04-01', '2026-02-01', '2026-03-01'];
        yield 'the 30th of March against February' => ['2026-03-30', '2026-03-01', '2026-03-31', '2026-02-01', '2026-03-01'];
        yield 'the 29th of March against a leap February is all of it' => ['2028-03-29', '2028-03-01', '2028-03-30', '2028-02-01', '2028-03-01'];
        yield 'January compares with December of the year before' => ['2026-01-15', '2026-01-01', '2026-01-16', '2025-12-01', '2025-12-16'];
        yield 'the 28th of February against January' => ['2026-02-28', '2026-02-01', '2026-03-01', '2026-01-01', '2026-01-29'];
    }

    #[DataProvider('days')]
    public function testTheMonthSoFarAndTheSameDaysOfTheMonthBefore(string $today, string $from, string $until, string $previousFrom, string $previousUntil): void
    {
        $window = MonthSoFar::of(new \DateTimeImmutable($today));

        self::assertSame([$from, $until, $previousFrom, $previousUntil], [
            $window->from->format('Y-m-d'),
            $window->until->format('Y-m-d'),
            $window->previousFrom->format('Y-m-d'),
            $window->previousUntil->format('Y-m-d'),
        ]);
    }
}

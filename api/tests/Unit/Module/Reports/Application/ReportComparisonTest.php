<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Reports\Application;

use App\Module\Reports\Application\InvalidReportRequest;
use App\Module\Reports\Application\ReportComparison;
use App\Module\Reports\Application\ReportPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportComparisonTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function periods(): iterable
    {
        yield 'a whole month against the month before' => ['previous', '2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30'];
        yield 'a month so far against the same days of the month before' => ['previous', '2026-10-01', '2026-10-18', '2026-09-01', '2026-09-18'];
        yield 'a day past a shorter month is its last day' => ['previous', '2026-03-01', '2026-03-30', '2026-02-01', '2026-02-28'];
        yield 'a month ending on its last day ends the month compared on its own' => ['previous', '2026-02-01', '2026-02-28', '2026-01-01', '2026-01-31'];
        yield 'two months against the two before' => ['previous', '2026-09-01', '2026-10-31', '2026-07-01', '2026-08-31'];
        yield 'a quarter against the quarter before' => ['previous', '2026-07-01', '2026-09-30', '2026-04-01', '2026-06-30'];
        yield 'any other span against as many days just before' => ['previous', '2026-10-12', '2026-10-18', '2026-10-05', '2026-10-11'];
        yield 'one day against the day before' => ['previous', '2026-10-18', '2026-10-18', '2026-10-17', '2026-10-17'];
        yield 'a year earlier' => ['year', '2026-10-01', '2026-10-18', '2025-10-01', '2025-10-18'];
        yield 'a leap day a year earlier' => ['year', '2028-02-01', '2028-02-29', '2027-02-01', '2027-02-28'];
        yield 'a month ending on its last day, a year earlier' => ['year', '2027-02-01', '2027-02-28', '2026-02-01', '2026-02-28'];
    }

    #[DataProvider('periods')]
    public function testThePeriodComparedWith(string $comparison, string $from, string $to, string $expectedFrom, string $expectedTo): void
    {
        $compared = ReportComparison::from($comparison)->of(new ReportPeriod(new \DateTimeImmutable($from), new \DateTimeImmutable($to)));

        self::assertNotNull($compared);
        self::assertSame([$expectedFrom, $expectedTo], [$compared->from->format('Y-m-d'), $compared->to->format('Y-m-d')]);
    }

    public function testNoneComparesWithNothing(): void
    {
        self::assertNull(ReportComparison::None->of(new ReportPeriod(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'))));
    }

    public function testAPeriodEndingBeforeItStartsIsRefused(): void
    {
        $this->expectException(InvalidReportRequest::class);
        new ReportPeriod(new \DateTimeImmutable('2026-10-02'), new \DateTimeImmutable('2026-10-01'));
    }

    public function testAPeriodReadsAtMostAYearAndADay(): void
    {
        new ReportPeriod(new \DateTimeImmutable('2028-01-01'), new \DateTimeImmutable('2028-12-31'));

        $this->expectException(InvalidReportRequest::class);
        new ReportPeriod(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-02'));
    }

    public function testAPeriodHoldsWholeDays(): void
    {
        $period = new ReportPeriod(new \DateTimeImmutable('2026-10-01 13:45'), new \DateTimeImmutable('2026-10-02 08:00'));

        self::assertSame(['2026-10-01 00:00', '2026-10-02 00:00'], [$period->from->format('Y-m-d H:i'), $period->to->format('Y-m-d H:i')]);
    }
}

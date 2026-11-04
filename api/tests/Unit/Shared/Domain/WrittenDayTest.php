<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Service\WrittenDay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A day written with a format is one only when it reads back as written: `createFromFormat` rolls a day the calendar lacks over. */
final class WrittenDayTest extends TestCase
{
    public function testAReadDayIsTheDayWrittenAtMidnight(): void
    {
        $day = WrittenDay::parsed('2026-02-28');

        self::assertNotNull($day);
        self::assertSame('2026-02-28 00:00:00', $day->format('Y-m-d H:i:s'));
    }

    /** @return iterable<string, array{string}> */
    public static function daysTheCalendarLacks(): iterable
    {
        yield 'a thirteenth month' => ['2026-13-45'];
        yield 'the thirtieth of February' => ['2026-02-30'];
        yield 'the twenty-ninth of February of a common year' => ['2026-02-29'];
        yield 'a day of one digit' => ['2026-02-3'];
        yield 'another format' => ['28/02/2026'];
        yield 'nothing' => [''];
        yield 'a moment' => ['2026-02-28 10:00'];
    }

    #[DataProvider('daysTheCalendarLacks')]
    public function testADayThatDoesNotReadBackIsNone(string $written): void
    {
        self::assertNull(WrittenDay::parsed($written));
    }

    public function testTheTwentyNinthOfFebruaryOfALeapYearIsOne(): void
    {
        self::assertSame('2028-02-29', WrittenDay::parsed('2028-02-29')?->format('Y-m-d'));
    }

    public function testADayIsReadInTheZoneGiven(): void
    {
        $day = WrittenDay::parsed('2026-03-01', zone: new \DateTimeZone('Africa/Tunis'));

        self::assertNotNull($day);
        self::assertSame('Africa/Tunis', $day->getTimezone()->getName());
        self::assertSame('2026-03-01T00:00:00+01:00', $day->format(\DATE_ATOM));
    }

    public function testAnotherFormatReadsBackInItsOwn(): void
    {
        self::assertSame('2026-03-01 09:30', WrittenDay::parsed('2026-03-01 09:30', 'Y-m-d H:i')?->format('Y-m-d H:i'));
        self::assertNull(WrittenDay::parsed('2026-03-01 25:30', 'Y-m-d H:i'));
    }
}

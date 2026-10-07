<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Application;

use App\Module\Invoices\Application\ReminderSettings;
use App\Module\Invoices\Application\RemindLateInvoices;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The reminder calendar as written in its setting, and the stage a number of days late reaches on it. */
final class ReminderSettingsTest extends TestCase
{
    /** @return iterable<string, array{string, list<int>}> */
    public static function written(): iterable
    {
        yield 'the default' => ['7, 15, 30', [7, 15, 30]];
        yield 'out of order and repeated' => ['30,7 , 7', [7, 30]];
        yield 'a zero is no stage' => ['0, 5', [5]];
        yield 'not a calendar' => ['7; 15', []];
        yield 'empty' => ['', []];
        yield 'six stages are too many' => ['1, 2, 3, 4, 5, 6', []];
    }

    /** @param list<int> $stages */
    #[DataProvider('written')]
    public function testTheCalendarIsReadSmallestFirstAndOnceEach(string $written, array $stages): void
    {
        self::assertSame($stages, ReminderSettings::stages($written));
    }

    public function testTheStageIsTheHighestReached(): void
    {
        $stages = [7, 15, 30];
        self::assertSame([0, 1, 1, 2, 3, 3], array_map(static fn (int $days): int => RemindLateInvoices::stageFor($stages, $days), [6, 7, 14, 15, 30, 400]));
        self::assertSame(0, RemindLateInvoices::stageFor([], 90), 'no calendar, no stage');
    }
}

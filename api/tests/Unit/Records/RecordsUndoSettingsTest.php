<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Records;

use App\Records\Application\RecordsUndoSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A company's undo window is its own within the operator's: at least an hour, never longer than the operator keeps a
 * delete, even once the operator has shortened that below what the company saved.
 */
final class RecordsUndoSettingsTest extends TestCase
{
    /** @return iterable<string, array{int, string, int}> */
    public static function windows(): iterable
    {
        yield 'a company shorter than the operator keeps its own hours' => [5, 'PT24H', 5 * 3_600];
        yield 'a company at the operator reads the operator' => [24, 'PT24H', 86_400];
        yield 'a saved value above an operator lowered since reads the operator' => [48, 'PT24H', 86_400];
        yield 'below an hour reads an hour' => [0, 'PT24H', 3_600];
        yield 'an operator off the hour is kept whole, not cut to its hours' => [1, 'PT90M', 5_400];
        yield 'an operator in days' => [72, 'P7D', 72 * 3_600];
    }

    #[DataProvider('windows')]
    public function testTheWindowIsTheCompanysWithinTheOperators(int $hours, string $operator, int $seconds): void
    {
        self::assertSame($seconds, RecordsUndoSettings::secondsOf($hours, $operator));
    }

    public function testTheCompanysChoiceIsBoundedByTheOperatorsWholeHours(): void
    {
        self::assertSame(24, RecordsUndoSettings::operatorHours('PT24H'));
        self::assertSame(1, RecordsUndoSettings::operatorHours('PT90M'));
        self::assertSame(168, RecordsUndoSettings::operatorHours('P7D'));
    }

    /** @return iterable<string, array{string, int}> */
    public static function operators(): iterable
    {
        yield 'a day' => ['PT24H', 24];
        yield 'a week' => ['P7D', 168];
    }

    /** What a company saves is refused below an hour and above the operator's hours, so the page says it out of range. */
    #[DataProvider('operators')]
    public function testASaveOutsideAnHourAndTheOperatorsHoursIsRefused(string $operator, int $hours): void
    {
        $definitions = [...(new RecordsUndoSettings($operator))->settings()];
        self::assertCount(1, $definitions);
        $definition = $definitions[0];
        self::assertSame(RecordsUndoSettings::UNDOABLE_FOR, $definition->key);

        self::assertSame($hours, $definition->default);
        self::assertNull($definition->refusal(1));
        self::assertNull($definition->refusal($hours));
        self::assertNotNull($definition->refusal(0), 'below an hour');
        self::assertNotNull($definition->refusal($hours + 1), 'above the operator');
    }
}

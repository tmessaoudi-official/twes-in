<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\ImportExport\Application;

use App\ImportExport\Application\ImportCells;
use App\ImportExport\Application\ImportRecord;
use App\ImportExport\Application\Notation\DayOrder;
use App\ImportExport\Application\Notation\DecimalMark;
use App\ImportExport\Application\Notation\ImportNotation;
use App\ImportExport\Application\RowRejected;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A cell read as the spreadsheets of the people who fill them in write it (docs/SPEC.md § 7, 2026-10-09 10:40). */
#[CoversClass(ImportCells::class)]
final class ImportCellsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function writtenDecimals(): iterable
    {
        yield 'a point' => ['12.5', '12.5'];
        yield 'a comma, as French and Tunisian spreadsheets write it' => ['12,5', '12.5'];
        yield 'thousands apart with a space' => ['1 234,500', '1234.500'];
        yield 'thousands apart with a no-break space' => ["1\u{00A0}234,500", '1234.500'];
        yield 'thousands apart with a narrow no-break space' => ["1\u{202F}234,5", '1234.5'];
        yield 'zeros past the scale say nothing more' => ['12.30000', '12.3'];
        yield 'a whole number' => ['7', '7'];
        yield 'a float a spreadsheet computed' => ['14.875000000000002', '14.875'];
        yield 'a float a spreadsheet computed, just under' => ['0.29999999999999999', '0.3'];
        yield 'what is no number, left to the rule that refuses it' => ['douze', 'douze'];
    }

    #[DataProvider('writtenDecimals')]
    public function testADecimalIsReadAsItsSpreadsheetWritesIt(string $cell, string $read): void
    {
        self::assertSame($read, ImportCells::decimal($this->row(['price' => $cell]), 'price', 3));
    }

    public function testABlankCellIsNoValue(): void
    {
        self::assertNull(ImportCells::decimal($this->row(['price' => '']), 'price', 3));
    }

    public function testMoreDecimalsThanTheColumnHoldsAreRefusedNamingItsMaximum(): void
    {
        try {
            ImportCells::decimal($this->row(['price' => '9,12345']), 'price', 4);
            self::fail('five decimals were taken in a column of four');
        } catch (RowRejected $refused) {
            self::assertSame(['price', 'too_many_decimals', ['max' => 4]], [$refused->column, $refused->reason, $refused->params]);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function percentages(): iterable
    {
        yield 'a cell Excel shows as a percentage, read as it showed' => ['5%'];
        yield 'typed with its space' => ['5 %'];
        yield 'typed with a no-break space and a comma' => ["12,5\u{00A0}%"];
        yield 'below zero' => ['-2%'];
    }

    /**
     * A percentage is refused rather than guessed: a discount column holds 5 for 5 %, and « 5 % » read as 0.05 would
     * grant a twentieth of one percent (docs/SPEC.md § 7, 2026-10-09 10:40, ruling 7).
     */
    #[DataProvider('percentages')]
    public function testAPercentageIsRefusedSayingHowToWriteIt(string $cell): void
    {
        try {
            ImportCells::decimal($this->row(['discount' => $cell]), 'discount', 3);
            self::fail(\sprintf('« %s » was taken', $cell));
        } catch (RowRejected $refused) {
            self::assertSame(['discount', 'percent_sign', []], [$refused->column, $refused->reason, $refused->params]);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function writtenDays(): iterable
    {
        yield 'as French and Tunisian people write it' => ['15/03/2026', '2026-03-15'];
        yield 'without its leading zeros' => ['5/3/2026', '2026-03-05'];
        yield 'already in the notation the field keeps' => ['2026-03-15', '2026-03-15'];
        yield 'a day no calendar has, left to the rule that refuses it' => ['31/02/2026', '31/02/2026'];
    }

    #[DataProvider('writtenDays')]
    public function testADayIsReadDayFirst(string $cell, string $read): void
    {
        self::assertSame($read, ImportCells::day($cell));
    }

    /** @return iterable<string, array{DecimalMark, string, string}> */
    public static function markedDecimals(): iterable
    {
        yield 'a point, commas grouping thousands' => [DecimalMark::Point, '1,234.50', '1234.50'];
        yield 'a point, two commas grouping millions' => [DecimalMark::Point, '1,234,567', '1234567'];
        yield 'a comma, points grouping thousands' => [DecimalMark::Comma, '1.234,50', '1234.50'];
        yield 'a comma, two points grouping millions' => [DecimalMark::Comma, '1.234.567', '1234567'];
        yield 'a comma, alone' => [DecimalMark::Comma, '12,5', '12.5'];
        yield 'automatic reads either as the decimal mark, as before' => [DecimalMark::Automatic, '1,5', '1.5'];
    }

    /** @return iterable<string, array{DecimalMark, string, string}> */
    public static function guessedMarks(): iterable
    {
        yield 'a point in a comma file, not grouping' => [DecimalMark::Comma, '1.5', ','];
        yield 'a point in a comma file, one group alone: twelve thousand or twelve and a half' => [DecimalMark::Comma, '12.500', ','];
        yield 'a comma in a point file, one group alone' => [DecimalMark::Point, '1,234', '.'];
        yield 'a comma in a point file, not grouping' => [DecimalMark::Point, '1,5', '.'];
        yield 'groups of the wrong size' => [DecimalMark::Comma, '1.23,5', ','];
    }

    /**
     * The other mark is read as thousands set apart only beyond doubt, three digits a group with the decimal mark or a
     * second group beside them: a file in the other notation (an export re-imported) is refused rather than read a
     * thousand times larger.
     */
    #[DataProvider('guessedMarks')]
    public function testTheOtherMarkIsRefusedWhereOnlyAGuessReadsIt(DecimalMark $mark, string $cell, string $named): void
    {
        try {
            ImportCells::decimal(new ImportRecord(2, ['price' => $cell], notation: new ImportNotation($mark)), 'price', 3);
            self::fail(\sprintf('« %s » was read', $cell));
        } catch (RowRejected $refused) {
            self::assertSame(['price', 'decimal_mark', ['mark' => $named]], [$refused->column, $refused->reason, $refused->params]);
        }
    }

    /** A custom number field reads the file's notation as a price does: one reader for every number of a file. */
    public function testACustomNumberIsReadInTheFilesNotation(): void
    {
        $comma = new ImportNotation(DecimalMark::Comma);
        self::assertSame([1234.5, 7, null], [
            ImportCells::number(new ImportRecord(2, ['count' => '1.234,5'], notation: $comma), 'count'),
            ImportCells::number(new ImportRecord(2, ['count' => '7'], notation: $comma), 'count'),
            ImportCells::number(new ImportRecord(2, ['count' => 'douze'], notation: $comma), 'count'),
        ]);
        self::assertSame(1.5, ImportCells::number($this->row(['count' => '1,5']), 'count'));
    }

    /** A company whose CSV files group thousands says which mark is its decimal one (import.decimal_mark). */
    #[DataProvider('markedDecimals')]
    public function testADecimalIsReadWithTheCompanysMark(DecimalMark $mark, string $cell, string $read): void
    {
        self::assertSame($read, ImportCells::decimal(new ImportRecord(2, ['price' => $cell], notation: new ImportNotation($mark)), 'price', 3));
    }

    /** @return iterable<string, array{DayOrder, string, string}> */
    public static function orderedDays(): iterable
    {
        yield 'month first, as American files write it' => [DayOrder::MonthFirst, '03/15/2026', '2026-03-15'];
        yield 'year first' => [DayOrder::YearFirst, '2026/03/15', '2026-03-15'];
        yield 'a day written in the notation the field keeps, whatever the order' => [DayOrder::MonthFirst, '2026-03-15', '2026-03-15'];
        yield 'month first refuses what only day first reads' => [DayOrder::MonthFirst, '15/03/2026', '15/03/2026'];
    }

    /** A company whose files write days otherwise says in which order (import.day_order); ISO is read in every order. */
    #[DataProvider('orderedDays')]
    public function testADayIsReadInTheCompanysOrder(DayOrder $order, string $cell, string $read): void
    {
        self::assertSame($read, ImportCells::day($cell, $order));
    }

    /** @param array<string, string> $cells */
    private function row(array $cells): ImportRecord
    {
        return new ImportRecord(2, $cells);
    }
}

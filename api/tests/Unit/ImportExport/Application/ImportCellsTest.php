<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\ImportExport\Application;

use App\ImportExport\Application\ImportCells;
use App\ImportExport\Application\ImportRecord;
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

    /** @param array<string, string> $cells */
    private function row(array $cells): ImportRecord
    {
        return new ImportRecord(2, $cells);
    }
}

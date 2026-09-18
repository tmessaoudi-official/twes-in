<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Application\Spreadsheet\UnreadableSpreadsheet;
use App\Shared\Infrastructure\Spreadsheet\OpenSpoutReader;
use App\Shared\Infrastructure\Spreadsheet\OpenSpoutWriter;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The spreadsheet port's one adapter (docs/SPEC.md § 7, import and export). What a row of a file becomes is the whole
 * contract every importer downstream is built on, so the cases below are about fidelity rather than about openspout:
 * what a number, a date and an empty row turn into, and that a row keeps the number it has in the file.
 */
#[CoversClass(OpenSpoutReader::class)]
#[CoversClass(OpenSpoutWriter::class)]
final class SpreadsheetTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }
        $this->written = [];
    }

    /** @return iterable<string, array{SpreadsheetFormat}> */
    public static function formats(): iterable
    {
        yield 'csv' => [SpreadsheetFormat::Csv];
        yield 'xlsx' => [SpreadsheetFormat::Xlsx];
    }

    #[DataProvider('formats')]
    public function testARoundTripKeepsEveryCellAsItWasWritten(SpreadsheetFormat $format): void
    {
        $rows = [
            ['reference', 'name', 'price'],
            ['ART-001', 'Portable 14"', '1299.00'],
            ['ART-002', 'Écran 27" — 4K', '399.90'],
        ];

        self::assertSame($rows, array_values(iterator_to_array($this->roundTrip($rows, $format))));
    }

    /**
     * A row's key is its line in the file, so a reason can name it. openspout drops empty rows by default, which would
     * silently renumber every row below one — the reason "line 7" would then point at line 6.
     */
    #[DataProvider('formats')]
    public function testAnEmptyRowKeepsTheNumbersOfTheRowsBelowIt(SpreadsheetFormat $format): void
    {
        $read = $this->roundTrip([['reference'], ['ART-001'], [''], ['ART-002']], $format);

        self::assertSame('ART-002', iterator_to_array($read)[4][0] ?? null);
    }

    /**
     * Excel hands a big number back as a float, and PHP's `precision` of 14 then casts it to `1.2345678901235E+14`:
     * a lost digit and a notation no importer parses. Money and long references both land in this case.
     *
     * The fixture is built through openspout itself rather than through our own writer, which takes strings: written as
     * a string this would be read back as a string whatever the conversion did, and the case would pass while proving
     * nothing. A number has to reach the file as a number for the reader to be the thing under test.
     */
    public function testALongNumberSurvivesAsItsDigitsRatherThanAsScientificNotation(): void
    {
        $path = $this->typed([[123456789012345, 123456789012345.67, 19.99]]);

        self::assertSame(
            ['123456789012345', '123456789012345.67', '19.99'],
            iterator_to_array((new OpenSpoutReader())->rows($path, SpreadsheetFormat::Xlsx))[1],
        );
    }

    /** A date cell is a date to Excel and a string to us, in the one notation every parser downstream agrees on. */
    public function testADateCellIsReadAsAnIsoDate(): void
    {
        $path = $this->typed([[new \DateTimeImmutable('2026-09-18 14:30:00')]]);

        self::assertSame('2026-09-18', iterator_to_array((new OpenSpoutReader())->rows($path, SpreadsheetFormat::Xlsx))[1][0]);
    }

    /**
     * What French Excel writes when it is asked for a CSV: semicolons, Windows-1252 and no byte-order mark. Read as
     * UTF-8 with commas it is one unreadable cell per line, so the file has to be sniffed rather than assumed.
     */
    public function testACsvFromFrenchExcelIsReadWithItsOwnSeparatorAndEncoding(): void
    {
        $path = $this->path('csv');
        $this->written[] = $path;
        file_put_contents($path, mb_convert_encoding("reference;name\nART-001;Écran café\n", 'Windows-1252', 'UTF-8'));

        self::assertSame(
            [1 => ['reference', 'name'], 2 => ['ART-001', 'Écran café']],
            iterator_to_array((new OpenSpoutReader())->rows($path, SpreadsheetFormat::Csv)),
        );
    }

    public function testAFileThatIsNotASpreadsheetIsRefusedByName(): void
    {
        $path = $this->path('xlsx');
        $this->written[] = $path;
        file_put_contents($path, 'this is not a workbook');

        $this->expectException(UnreadableSpreadsheet::class);
        iterator_to_array((new OpenSpoutReader())->rows($path, SpreadsheetFormat::Xlsx));
    }

    /**
     * @param list<list<string>> $rows
     *
     * @return iterable<int, list<string>>
     */
    private function roundTrip(array $rows, SpreadsheetFormat $format): iterable
    {
        $path = $this->path($format->extension());
        (new OpenSpoutWriter())->write($path, $format, $rows);

        return (new OpenSpoutReader())->rows($path, $format);
    }

    /**
     * A workbook whose cells carry their real types, as Excel's own files do: openspout decides a cell's kind from the
     * PHP type it is handed, so an int and a float become numeric cells and a date becomes a date cell.
     *
     * @param list<list<\DateTimeImmutable|float|int|string>> $rows
     */
    private function typed(array $rows): string
    {
        // Two adjustments, both so that the fixture is a file EXCEL would write rather than one openspout would.
        // openspout interpolates a float straight into the sheet's XML, so PHP's `precision` of 14 truncates it on the
        // way in and the reader could never be shown the digits Excel stores; and it writes a date as a bare serial
        // number with no date format on the cell, which is exactly the shape a reader cannot tell from a number.
        // Modelling the dependency's own defaults here would make the case pass while testing nothing.
        $precision = ini_set('precision', '17');
        $date = (new Style())->withFormat('yyyy-mm-dd');

        try {
            $path = $this->path('xlsx');
            $writer = new XlsxWriter();
            $writer->openToFile($path);
            foreach ($rows as $row) {
                $writer->addRow(new Row(array_map(
                    static fn (\DateTimeImmutable|float|int|string $value): Cell => Cell::fromValue($value, $value instanceof \DateTimeInterface ? $date : null),
                    $row,
                )));
            }
            $writer->close();

            return $path;
        } finally {
            ini_set('precision', false === $precision ? '14' : $precision);
        }
    }

    private function path(string $extension): string
    {
        $path = sys_get_temp_dir().'/twes-spreadsheet-'.bin2hex(random_bytes(6)).'.'.$extension;
        $this->written[] = $path;

        return $path;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Application\Spreadsheet\TextEncoding;
use App\Shared\Application\Spreadsheet\UnreadableSpreadsheet;
use App\Shared\Application\Spreadsheet\UnwritableSpreadsheet;
use App\Shared\Infrastructure\Spreadsheet\CsvSniffer;
use App\Shared\Infrastructure\Spreadsheet\CsvTranscoder;
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
#[CoversClass(CsvTranscoder::class)]
#[CoversClass(CsvSniffer::class)]
#[CoversClass(OpenSpoutWriter::class)]
final class SpreadsheetTest extends TestCase
{
    private const int MAX_INFLATED_BYTES = 1024 * 1024;

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
     * Audit 2026-10-06, D-2: a CSV cell opened in a spreadsheet that begins like a formula is run as one, which is how a
     * customer's name such as `=HYPERLINK(...)` or `-2+3+cmd|...` reaches whoever opens the export. In the file such a
     * cell starts with a quote, which a spreadsheet shows as text; a plain negative amount is a number and stays as it is.
     */
    public function testACsvCellThatASpreadsheetWouldRunAsAFormulaIsWrittenAsText(): void
    {
        $path = $this->path('csv');
        (new OpenSpoutWriter())->write($path, SpreadsheetFormat::Csv, [[
            '=HYPERLINK("http://x","y")', '+216 22 333 444', '@SUM(A1)', '-2+3+cmd', "\t=1", "\r=1", '-12.500', '-7', 'ART-001',
        ]]);

        $written = (string) file_get_contents($path);
        foreach (['\'=HYPERLINK', '\'+216', '\'@SUM', '\'-2+3', "'\t=1", "'\r=1"] as $quoted) {
            self::assertStringContainsString($quoted, $written, $quoted);
        }
        self::assertStringNotContainsString("'-12.500", $written, 'a negative amount is a number');
        self::assertStringNotContainsString("'-7", $written);
        self::assertStringNotContainsString("'ART", $written);
    }

    public function testACsvCellQuotedAgainstFormulasReadsBackAsItWasWritten(): void
    {
        $rows = [['=1+1', '+216 22 333 444', '@x', '-2+3', '-12.500', "'quoted by its author"]];

        self::assertSame($rows, array_values(iterator_to_array($this->roundTrip($rows, SpreadsheetFormat::Csv))));
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
            iterator_to_array((new OpenSpoutReader(self::MAX_INFLATED_BYTES))->rows($path, SpreadsheetFormat::Xlsx))[1],
        );
    }

    /**
     * A cell Excel computes holds its formula and the value Excel last worked out, which is what the person saw: a price
     * written « =A1*1.19 » is that price, never the formula's text.
     */
    public function testAComputedCellIsReadAsTheValueItShowed(): void
    {
        $path = $this->typed([[12.5, '=A1*1.19']]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        self::assertStringContainsString('<f>A1*1.19</f>', $sheet, 'the fixture holds the formula');
        // What Excel stores beside a formula: the value it computed, here with a binary float's noise.
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<f>A1*1.19</f>', '<f>A1*1.19</f><v>14.875000000000002</v>', $sheet));
        $zip->close();

        self::assertSame(['12.5', '14.875000000000002'], iterator_to_array((new OpenSpoutReader(self::MAX_INFLATED_BYTES))->rows($path, SpreadsheetFormat::Xlsx))[1]);
    }

    /** A date cell is a date to Excel and a string to us, in the one notation every parser downstream agrees on. */
    public function testADateCellIsReadAsAnIsoDate(): void
    {
        $path = $this->typed([[new \DateTimeImmutable('2026-09-18 14:30:00')]]);

        self::assertSame('2026-09-18', iterator_to_array((new OpenSpoutReader(self::MAX_INFLATED_BYTES))->rows($path, SpreadsheetFormat::Xlsx))[1][0]);
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
            iterator_to_array((new OpenSpoutReader(self::MAX_INFLATED_BYTES))->rows($path, SpreadsheetFormat::Csv)),
        );
    }

    /**
     * The encoding is found on the whole file, never on its first pages, and an Arabic word tells Windows-1256 from
     * Windows-1252: Arabic writes letters in runs, French writes its accents alone or doubled.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function encodedFiles(): iterable
    {
        yield 'a short French file whose one accent is a Spanish name\'s' => ["number;name\nCLI-0001;MUÑOZ\n", 'Windows-1252'];
        yield 'French with guillemets, a no-break space and « créée »' => ["number;name\nCLI-0001;« Économie » créée à Nîmes\n", 'Windows-1252'];
        yield 'mostly ASCII with one three-letter Arabic word' => ["number;name\nCLI-0001;Quincaillerie Ben Salah\nCLI-0002;Atelier Mansour\nCLI-0003;نهج\n", 'CP1256'];
        yield 'Arabic and Latin side by side' => ["number;name\nCLI-0001;Quincaillerie الأمل Sfax\n", 'CP1256'];
        yield 'accents only past the first pages' => ["number;name\n".str_repeat("CLI-0001;Client\n", 5000)."CLI-0002;Société\n", 'Windows-1252'];
    }

    #[DataProvider('encodedFiles')]
    public function testACsvIsReadInTheEncodingItWasWrittenIn(string $text, string $encoding): void
    {
        $path = $this->path('csv');
        $bytes = 'CP1256' === $encoding ? iconv('UTF-8', 'CP1256', $text) : mb_convert_encoding($text, $encoding, 'UTF-8');
        file_put_contents($path, $bytes);

        $rows = iterator_to_array((new OpenSpoutReader(self::MAX_INFLATED_BYTES))->rows($path, SpreadsheetFormat::Csv));

        self::assertSame(array_map(static fn (string $line): array => explode(';', $line), explode("\n", rtrim($text, "\n"))), array_values($rows));
    }

    /** A character a chunk's edge cuts in two is judged whole: a long UTF-8 file stays UTF-8, a UTF-16 pair stays one. */
    public function testACharacterCutByTheEdgeOfAChunkIsReadWhole(): void
    {
        $utf8 = $this->path('csv');
        $text = "name\n".str_repeat("é😀ج\n", 30000);
        file_put_contents($utf8, $text);
        $utf16 = $this->path('csv');
        file_put_contents($utf16, "\xFF\xFE".mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'));
        $reader = new OpenSpoutReader(self::MAX_INFLATED_BYTES);

        foreach ([$utf8, $utf16] as $path) {
            $rows = iterator_to_array($reader->rows($path, SpreadsheetFormat::Csv));
            self::assertCount(30001, $rows);
            self::assertSame([], array_filter($rows, static fn (array $row): bool => $row !== ['name'] && $row !== ['é😀ج']));
        }
    }

    /** The copy holds the text alone: a byte-order mark is no letter, and kept it would open the first column's name. */
    public function testTheUtf8CopyOfAUtf16FileCarriesNoByteOrderMark(): void
    {
        foreach (["\xFF\xFE" => 'UTF-16LE', "\xFE\xFF" => 'UTF-16BE'] as $mark => $encoding) {
            $from = $this->path('csv');
            file_put_contents($from, $mark.mb_convert_encoding("name\nشركة\n", $encoding, 'UTF-8'));
            $to = $this->path('csv');

            CsvTranscoder::toUtf8($from, $to, TextEncoding::Utf16);

            self::assertSame("name\nشركة\n", file_get_contents($to), $encoding);
        }
    }

    /** A file read in another encoding is read from a UTF-8 copy, gone once the reading ends, finished or not. */
    public function testTheCopyOfAFileReadInAnotherEncodingIsGoneOnceTheReadingEnds(): void
    {
        $copies = sys_get_temp_dir().'/twes-copies-'.bin2hex(random_bytes(6));
        mkdir($copies);
        $path = $this->path('csv');
        file_put_contents($path, mb_convert_encoding("name\nSociété\nCafé\n", 'Windows-1252', 'UTF-8'));
        $reader = new OpenSpoutReader(self::MAX_INFLATED_BYTES, $copies);

        $rows = $reader->rows($path, SpreadsheetFormat::Csv);
        foreach ($rows as $row) {
            self::assertSame(['name'], $row);
            self::assertCount(1, (array) glob($copies.'/*'), 'read from a copy');
            break;
        }
        unset($rows);
        $finished = iterator_to_array($reader->rows($path, SpreadsheetFormat::Csv));

        self::assertSame(['Café'], $finished[3]);
        self::assertSame([], glob($copies.'/*'));
        rmdir($copies);
    }

    /**
     * A workbook is a zip, and a few kilobytes of it can inflate to gigabytes, which the reader would then cache and
     * walk. What the zip says its parts inflate to is read before anything is opened.
     */
    public function testAWorkbookThatInflatesBeyondTheLimitIsRefusedBeforeItIsRead(): void
    {
        $path = $this->path('xlsx');
        (new OpenSpoutWriter())->write($path, SpreadsheetFormat::Xlsx, [['number', 'name'], ['A-1', 'Alpha']]);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $zip->addFromString('xl/padding.xml', str_repeat(' ', self::MAX_INFLATED_BYTES));
        $zip->close();
        self::assertLessThan(self::MAX_INFLATED_BYTES / 10, (int) filesize($path), 'the file itself is small');

        $this->expectException(UnreadableSpreadsheet::class);
        iterator_to_array((new OpenSpoutReader(self::MAX_INFLATED_BYTES))->rows($path, SpreadsheetFormat::Xlsx));
    }

    public function testAWorkbookWithinTheLimitIsRead(): void
    {
        $path = $this->path('xlsx');
        (new OpenSpoutWriter())->write($path, SpreadsheetFormat::Xlsx, [['number', 'name'], ['A-1', 'Alpha']]);

        self::assertSame([1 => ['number', 'name'], 2 => ['A-1', 'Alpha']], iterator_to_array((new OpenSpoutReader(self::MAX_INFLATED_BYTES))->rows($path, SpreadsheetFormat::Xlsx)));
    }

    public function testAFileThatIsNotASpreadsheetIsRefusedByName(): void
    {
        $path = $this->path('xlsx');
        $this->written[] = $path;
        file_put_contents($path, 'this is not a workbook');

        $this->expectException(UnreadableSpreadsheet::class);
        iterator_to_array((new OpenSpoutReader(self::MAX_INFLATED_BYTES))->rows($path, SpreadsheetFormat::Xlsx));
    }

    /**
     * A file that cannot be written is a disk or a library refusing, worth trying again; a bug while the rows are made is
     * not, and must not read as one (audit 2026-10-06, G-12).
     */
    public function testAnErrorWhileTheRowsAreMadeIsNotTakenForAFileThatCouldNotBeWritten(): void
    {
        $path = $this->path('csv');
        $this->written[] = $path;
        $rows = (static function (): iterable {
            yield ['a'];

            throw new \LogicException('a row mapping went wrong');
        })();

        $this->expectException(\LogicException::class);
        (new OpenSpoutWriter())->write($path, SpreadsheetFormat::Csv, $rows);
    }

    public function testAPlaceThatCannotTakeTheFileIsAFileThatCouldNotBeWritten(): void
    {
        $this->expectException(UnwritableSpreadsheet::class);
        (new OpenSpoutWriter())->write('/nonexistent-directory/export.csv', SpreadsheetFormat::Csv, [['a']]);
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

        return (new OpenSpoutReader(self::MAX_INFLATED_BYTES))->rows($path, $format);
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

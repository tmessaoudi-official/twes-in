<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Spreadsheet;

use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Application\Spreadsheet\SpreadsheetReader;
use App\Shared\Application\Spreadsheet\TextEncoding;
use App\Shared\Application\Spreadsheet\UnreadableSpreadsheet;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The spreadsheet port on openspout, the one file in this codebase that names it. It streams: a row is read, handed
 * over and forgotten, so the memory a 50 000-row import takes does not depend on how long the file is.
 */
final class OpenSpoutReader implements SpreadsheetReader
{
    /** How much of a part the document type scan reads at a time. */
    public const int SCAN_CHUNK = 65536;

    /** « DOCTYPE » in EBCDIC, the same in every one of its code pages (the « <! » before it is not). */
    private const string EBCDIC_DOCTYPE = "\xC4\xD6\xC3\xE3\xE8\xD7\xC5";

    /**
     * @param int    $maxInflatedBytes what a workbook's parts may inflate to, together: a few kilobytes of zip can hold gigabytes
     * @param string $copies           where a CSV in another encoding is written out in UTF-8 while it is read; empty for
     *                                 the system's temporary directory
     */
    public function __construct(
        #[Autowire(param: 'app.import.max_inflated_bytes')] private readonly int $maxInflatedBytes,
        private readonly string $copies = '',
    ) {
    }

    public function encodingOf(string $path, SpreadsheetFormat $format): ?TextEncoding
    {
        return SpreadsheetFormat::Csv === $format ? CsvSniffer::encodingOf($path) : null;
    }

    /**
     * Excel keeps a number as a float and a date as a number of days. Both are converted here rather than downstream,
     * so that every importer parses text and only text.
     */
    public function rows(string $path, SpreadsheetFormat $format, ?TextEncoding $encoding = null): iterable
    {
        if (SpreadsheetFormat::Csv !== $format || TextEncoding::Utf8 === ($encoding ??= CsvSniffer::encodingOf($path))) {
            yield from $this->read($path, $format);

            return;
        }

        $copy = tempnam('' === $this->copies ? sys_get_temp_dir() : $this->copies, 'twes-csv-');
        if (false === $copy) {
            throw new UnreadableSpreadsheet(\sprintf('"%s" could not be read.', basename($path)));
        }
        // Removed however the reading ends: finished, refused, or left halfway by a caller that stopped asking.
        try {
            CsvTranscoder::toUtf8($path, $copy, $encoding);
            yield from $this->read($copy, $format);
        } finally {
            unlink($copy);
        }
    }

    /**
     * @return iterable<int, list<string>>
     *
     * @throws UnreadableSpreadsheet
     */
    private function read(string $path, SpreadsheetFormat $format): iterable
    {
        $reader = match ($format) {
            SpreadsheetFormat::Csv => new CsvReader(CsvSniffer::optionsFor($path)),
            // Dates stay dates rather than being formatted by the cell's own format, which is the author's locale
            // and not a contract; empty rows stay so that a row keeps the line number its author sees.
            SpreadsheetFormat::Xlsx => new XlsxReader(new XlsxOptions(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: true)),
        };

        if (SpreadsheetFormat::Xlsx === $format) {
            $this->refuseWhatInflatesTooFar($path);
            self::refuseDeclaredDocumentTypes($path);
        }

        try {
            $reader->open($path);
        } catch (\Throwable $failure) {
            throw new UnreadableSpreadsheet(\sprintf('"%s" could not be read as %s.', basename($path), $format->value), previous: $failure);
        }

        // Read before openspout, which knows a cell's format but keeps it: what Excel shows as 5 % it holds as 0.05.
        $percentages = SpreadsheetFormat::Xlsx === $format ? XlsxPercentCells::of($path) : [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $number => $row) {
                    // openspout keys a row by its line in the file; asserted rather than assumed, because that key
                    // is what every rejected row's reason will name.
                    \assert(\is_int($number));

                    $cells = [];
                    foreach (array_values($row->cells) as $column => $cell) {
                        $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();
                        $cells[] = isset($percentages[$number][$column]) ? self::percentage($value) : self::text($value);
                    }

                    yield $number => SpreadsheetFormat::Csv === $format ? array_map(CsvFormula::restored(...), $cells) : $cells;
                }

                // A template has one sheet, and a person's own file is asked to put its data in the first.
                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Read from the zip's own directory, before anything is inflated. A directory that understates a part is caught
     * by the zip library itself, which refuses a part whose bytes do not match what it declared.
     *
     * @throws UnreadableSpreadsheet
     */
    private function refuseWhatInflatesTooFar(string $path): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            // Not a zip at all: the reader says so in its own words.
            return;
        }
        try {
            $inflated = 0;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $inflated += (int) ($zip->statIndex($index)['size'] ?? 0);
                if ($inflated > $this->maxInflatedBytes) {
                    throw new UnreadableSpreadsheet(\sprintf('"%s" inflates to more than %d bytes.', basename($path), $this->maxInflatedBytes));
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * No workbook a spreadsheet application writes declares a document type, and libxml2, which openspout and
     * `XlsxPercentCells` parse with, has had its flaws in handling one (CVE-2026-6653, unfixed in the image's Debian): a
     * workbook any of whose parts declares one is refused before anything parses it. Every part is read, whatever its
     * name, since a relationship may name a sheet anything. Read a piece at a time, the end of each piece carried into
     * the next, with NUL bytes dropped so that a part in UTF-16 or UTF-32 reads as ASCII would; EBCDIC, which libxml2
     * also recognises, is looked for by its own bytes. Called after `refuseWhatInflatesTooFar`, which bounds the reading.
     *
     * @throws UnreadableSpreadsheet
     */
    private static function refuseDeclaredDocumentTypes(string $path): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            // Not a zip at all: the reader says so in its own words.
            return;
        }
        try {
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $part = $zip->getStreamIndex($index);
                if (false === $part) {
                    // A part that cannot be read cannot be vouched for.
                    throw new UnreadableSpreadsheet(\sprintf('"%s" has a part that cannot be read.', basename($path)));
                }
                try {
                    self::refuseADeclarationIn($part, $path);
                } finally {
                    fclose($part);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param resource $part
     *
     * @throws UnreadableSpreadsheet
     */
    private static function refuseADeclarationIn($part, string $path): void
    {
        $carried = '';
        while (!feof($part)) {
            $piece = fread($part, self::SCAN_CHUNK);
            if (false === $piece) {
                throw new UnreadableSpreadsheet(\sprintf('"%s" has a part that cannot be read.', basename($path)));
            }
            $text = $carried.str_replace("\0", '', $piece);
            if (false !== stripos($text, '<!DOCTYPE') || str_contains($text, self::EBCDIC_DOCTYPE)) {
                throw new UnreadableSpreadsheet(\sprintf('"%s" declares a document type.', basename($path)), reason: UnreadableSpreadsheet::DOCUMENT_TYPE);
            }
            // The longer needle less one byte: a declaration cut between two pieces is found whole in the next.
            $carried = substr($text, -(\strlen('<!DOCTYPE') - 1));
        }
    }

    /**
     * What a cell becomes as text. A computed cell is the value its spreadsheet last worked out, which is what the person
     * saw; one no spreadsheet ever computed has none, and reads blank.
     *
     * A float is written by `json_encode` rather than cast: PHP's `precision` of 14 turns 123456789012345.67 into
     * "1.2345678901235E+14", losing a digit and producing a notation no parser downstream reads, while
     * `serialize_precision` gives back the shortest text that is still the same number. An int is already exact.
     */
    /** A number shown as a percentage, read as it showed: 0.05 is « 5% ». */
    private static function percentage(mixed $value): string
    {
        if (!\is_int($value) && !\is_float($value)) {
            return self::text($value);
        }

        // float: Excel keeps the percentage as a float; ten decimals hold any a column takes, and round its noise away.
        return rtrim(rtrim(number_format($value * 100, 10, '.', ''), '0'), '.').'%';
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? '1' : '0',
            \is_float($value) => json_encode($value, \JSON_PRESERVE_ZERO_FRACTION) ?: '',
            \is_int($value) => (string) $value,
            \is_string($value) => $value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof \DateInterval => $value->format('%H:%I:%S'),
            // Every kind of cell openspout reads is covered above — rich text comes back as a string, and an error
            // cell holds one. The arm is here so a kind added later arrives empty rather than as a cast of an object.
            default => '',
        };
    }
}

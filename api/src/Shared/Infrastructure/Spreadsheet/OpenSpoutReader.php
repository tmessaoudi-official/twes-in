<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Spreadsheet;

use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Application\Spreadsheet\SpreadsheetReader;
use App\Shared\Application\Spreadsheet\UnreadableSpreadsheet;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * The spreadsheet port on openspout, the one file in this codebase that names it. It streams: a row is read, handed
 * over and forgotten, so the memory a 50 000-row import takes does not depend on how long the file is.
 */
final class OpenSpoutReader implements SpreadsheetReader
{
    /**
     * Excel keeps a number as a float and a date as a number of days. Both are converted here rather than downstream,
     * so that every importer parses text and only text.
     */
    public function rows(string $path, SpreadsheetFormat $format): iterable
    {
        $reader = match ($format) {
            SpreadsheetFormat::Csv => new CsvReader(CsvSniffer::optionsFor($path)),
            // Dates stay dates rather than being formatted by the cell's own format, which is the author's locale
            // and not a contract; empty rows stay so that a row keeps the line number its author sees.
            SpreadsheetFormat::Xlsx => new XlsxReader(new XlsxOptions(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: true)),
        };

        try {
            $reader->open($path);
        } catch (\Throwable $failure) {
            throw new UnreadableSpreadsheet(\sprintf('"%s" could not be read as %s.', basename($path), $format->value), previous: $failure);
        }

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $number => $row) {
                    // openspout keys a row by its line in the file; asserted rather than assumed, because that key
                    // is what every rejected row's reason will name.
                    \assert(\is_int($number));

                    yield $number => array_values(array_map(self::text(...), $row->toArray()));
                }

                // A template has one sheet, and a person's own file is asked to put its data in the first.
                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * What a cell becomes as text.
     *
     * A float is written by `json_encode` rather than cast: PHP's `precision` of 14 turns 123456789012345.67 into
     * "1.2345678901235E+14", losing a digit and producing a notation no parser downstream reads, while
     * `serialize_precision` gives back the shortest text that is still the same number. An int is already exact.
     */
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

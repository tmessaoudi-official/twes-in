<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Spreadsheet;

use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Application\Spreadsheet\SpreadsheetWriter;
use App\Shared\Application\Spreadsheet\UnwritableSpreadsheet;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * The writing half of the spreadsheet port on openspout. It streams too: rows are written as they arrive, so an
 * export of a long list is bounded by the page the API answers rather than by the size of the list.
 */
final class OpenSpoutWriter implements SpreadsheetWriter
{
    public function write(string $path, SpreadsheetFormat $format, iterable $rows): void
    {
        $writer = $this->writerFor($format);

        try {
            $writer->openToFile($path);
            foreach ($rows as $row) {
                $writer->addRow(new Row(array_map(self::cell(...), $row)));
            }
            $writer->close();
        } catch (\Throwable $failure) {
            throw new UnwritableSpreadsheet(\sprintf('"%s" could not be written.', basename($path)), previous: $failure);
        }
    }

    /**
     * Every cell is written as text, never as a number.
     *
     * A reference like "0012" handed to a spreadsheet as a number comes back as 12, and an amount handed over as a
     * number round-trips through a float. Both are already decided elsewhere in this codebase: an amount is a decimal
     * string, and a reference is whatever its owner typed. A spreadsheet's job here is to carry them, not to read them.
     */
    private static function cell(string $value): Cell
    {
        return new Cell\StringCell($value, null);
    }

    private function writerFor(SpreadsheetFormat $format): WriterInterface
    {
        return match ($format) {
            // A byte-order mark, so that Excel opens an accented export as UTF-8 instead of as the system's own
            // code page, which is what turns "Écran" into "Ãcran" on a French Windows.
            SpreadsheetFormat::Csv => new CsvWriter(new CsvOptions(SHOULD_ADD_BOM: true)),
            SpreadsheetFormat::Xlsx => new XlsxWriter(),
        };
    }
}

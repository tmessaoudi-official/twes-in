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
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Exception\OpenSpoutException;
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
    /** Excel's own Text format, under which what is typed is kept as typed. */
    private const string TEXT = '@';

    private const string SHEET = 'xl/worksheets/sheet1.xml';

    public function write(string $path, SpreadsheetFormat $format, iterable $rows, array $textColumns = []): void
    {
        $writer = $this->writerFor($format);
        $csv = SpreadsheetFormat::Csv === $format;
        $text = (new Style())->withFormat(self::TEXT);

        try {
            $writer->openToFile($path);
            foreach ($rows as $row) {
                $cells = [];
                foreach ($row as $column => $value) {
                    $cells[] = $csv
                        ? self::cell(CsvFormula::defused($value), null)
                        : self::cell($value, \in_array($column, $textColumns, true) ? $text : null);
                }
                $writer->addRow(new Row($cells));
            }
            $writer->close();
        } catch (OpenSpoutException $failure) {
            // Only the library refusing the file is a file that could not be written, worth trying again: a bug while
            // the rows are made is left to surface as the error it is.
            throw new UnwritableSpreadsheet(\sprintf('"%s" could not be written.', basename($path)), previous: $failure);
        }

        // A CSV holds no format: what a spreadsheet makes of « 0012 » there is its own affair.
        if (!$csv && [] !== $textColumns) {
            self::keepAsText($path, $textColumns);
        }
    }

    /**
     * Every cell is written as text, never as a number.
     *
     * A reference like "0012" handed to a spreadsheet as a number comes back as 12, and an amount handed over as a
     * number round-trips through a float. Both are already decided elsewhere in this codebase: an amount is a decimal
     * string, and a reference is whatever its owner typed. A spreadsheet's job here is to carry them, not to read them.
     */
    private static function cell(string $value, ?Style $style): Cell
    {
        return new Cell\StringCell($value, $style);
    }

    /**
     * Formats whole columns as Text, as Excel saves a column so formatted: a `<col>` naming the style of the column's
     * first cell, which openspout writes but cannot give a column. What a person types there later is then kept as
     * typed, leading zeros and long barcodes included. The sheet is read whole, which only a template's few rows allow.
     *
     * @param list<int> $columns
     */
    private static function keepAsText(string $path, array $columns): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            throw new UnwritableSpreadsheet(\sprintf('"%s" could not be written.', basename($path)));
        }

        $sheet = $zip->getFromName(self::SHEET);
        if (false === $sheet || str_contains($sheet, '<cols>') || 1 !== substr_count($sheet, '<sheetData>')) {
            $zip->close();

            throw new \LogicException('openspout wrote a sheet whose columns this writer does not know how to format.');
        }

        $declared = '';
        foreach ($columns as $column) {
            $letters = self::letters($column);
            if (1 !== preg_match('/<c r="'.$letters.'1" s="([0-9]+)"/', $sheet, $style)) {
                $zip->close();

                throw new \LogicException(\sprintf('Column %s has no first cell to take its style from.', $letters));
            }
            $declared .= \sprintf('<col min="%1$d" max="%1$d" width="18" customWidth="1" style="%2$s"/>', $column + 1, $style[1]);
        }

        $zip->addFromString(self::SHEET, str_replace('<sheetData>', '<cols>'.$declared.'</cols><sheetData>', $sheet));
        if (!$zip->close()) {
            throw new UnwritableSpreadsheet(\sprintf('"%s" could not be written.', basename($path)));
        }
    }

    /** Column 0 is « A », 26 is « AA ». */
    private static function letters(int $column): string
    {
        $letters = '';
        for (++$column; $column > 0; $column = intdiv($column - 1, 26)) {
            $letters = \chr(65 + ($column - 1) % 26).$letters;
        }

        return $letters;
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

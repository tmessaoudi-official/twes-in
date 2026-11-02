<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Spreadsheet;

/**
 * The cells of a workbook's first sheet that Excel shows as a percentage. Excel keeps « 5 % » as 0.05 and only the
 * cell's format multiplies it by a hundred; openspout reads that format but does not hand it over, so it is read here
 * from the workbook's own parts, and such a cell can be read as what it showed.
 */
final class XlsxPercentCells
{
    /** Excel's own percentage formats, which a workbook names by number alone: « 0% » and « 0.00% ». */
    private const array BUILT_IN = [9, 10];

    /** A sheet's size, as Excel bounds it: 1,048,576 rows of 16,384 columns. */
    private const int LAST_LINE = 1_048_576;
    private const int COLUMNS = 16_384;

    /**
     * @return array<int, array<int, true>> by line, the columns (from 0) whose number is shown as a percentage
     */
    public static function of(string $path): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            return [];
        }
        // A part this scan cannot read is left to openspout, which refuses the file in its own words.
        $quiet = libxml_use_internal_errors(true);
        try {
            $styles = self::percentStyles(self::part($zip, 'xl/styles.xml'));
            $sheet = [] === $styles ? null : self::firstSheet(self::part($zip, 'xl/workbook.xml'), self::part($zip, 'xl/_rels/workbook.xml.rels'));
            $zip->close();

            return null === $sheet ? [] : self::cells('zip://'.$path.'#'.$sheet, $styles);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($quiet);
        }
    }

    /**
     * A part's bytes, or none when the zip cannot give them whole: a damaged part makes the zip library warn
     * (« CRC error », « Premature end of file ») and is left to openspout, which refuses the file in its own words.
     */
    private static function part(\ZipArchive $zip, string $name): string
    {
        $damaged = false;
        set_error_handler(static function () use (&$damaged): bool {
            $damaged = true;

            return true;
        });
        try {
            $bytes = $zip->getFromName($name);
        } finally {
            restore_error_handler();
        }

        return $damaged || false === $bytes ? '' : $bytes;
    }

    /**
     * A percent sign in quotes, escaped or in brackets is written out as it is, without multiplying: « 0"%" » shows 5
     * as « 5% ».
     */
    public static function showsAPercentage(string $code): bool
    {
        return str_contains((string) preg_replace('/"[^"]*"|\\\\.|\[[^\]]*\]/', '', $code), '%');
    }

    /**
     * @return array<int, true> the indexes of `cellXfs`, which a cell's `s` names, whose number format is a percentage
     */
    private static function percentStyles(string $xml): array
    {
        if ('' === $xml) {
            return [];
        }
        $reader = \XMLReader::fromString($xml, null, \LIBXML_NONET);
        $formats = [];
        $styles = [];
        $inCellFormats = false;
        $index = 0;
        while ($reader->read()) {
            if (\XMLReader::END_ELEMENT === $reader->nodeType && 'cellXfs' === $reader->localName) {
                $inCellFormats = false;
            }
            if (\XMLReader::ELEMENT !== $reader->nodeType) {
                continue;
            }
            if ('numFmt' === $reader->localName) {
                $formats[(int) $reader->getAttribute('numFmtId')] = (string) $reader->getAttribute('formatCode');
            } elseif ('cellXfs' === $reader->localName) {
                $inCellFormats = !$reader->isEmptyElement;
            } elseif ($inCellFormats && 'xf' === $reader->localName) {
                $format = (int) $reader->getAttribute('numFmtId');
                if (\in_array($format, self::BUILT_IN, true) || (isset($formats[$format]) && self::showsAPercentage($formats[$format]))) {
                    $styles[$index] = true;
                }
                ++$index;
            }
        }
        $reader->close();

        return $styles;
    }

    /** The first sheet's part, the one an import reads, as its relationship names it. */
    private static function firstSheet(string $workbook, string $relationships): ?string
    {
        if ('' === $workbook || '' === $relationships) {
            return null;
        }
        $reader = \XMLReader::fromString($workbook, null, \LIBXML_NONET);
        $id = null;
        while (null === $id && $reader->read()) {
            if (\XMLReader::ELEMENT === $reader->nodeType && 'sheet' === $reader->localName) {
                $id = $reader->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            }
        }
        $reader->close();
        if (null === $id) {
            return null;
        }

        $reader = \XMLReader::fromString($relationships, null, \LIBXML_NONET);
        $target = null;
        while (null === $target && $reader->read()) {
            if (\XMLReader::ELEMENT === $reader->nodeType && 'Relationship' === $reader->localName && $id === $reader->getAttribute('Id')) {
                $target = $reader->getAttribute('Target');
            }
        }
        $reader->close();

        // « worksheets/sheet1.xml » beside the workbook, or « /xl/worksheets/sheet1.xml » from the archive's root.
        return null === $target ? null : (str_starts_with($target, '/') ? substr($target, 1) : 'xl/'.$target);
    }

    /**
     * Streamed, as openspout reads it: a line or a cell without its reference follows the one before it.
     *
     * @param array<int, true> $styles
     *
     * @return array<int, array<int, true>>
     */
    private static function cells(string $part, array $styles): array
    {
        $reader = \XMLReader::fromUri($part, null, \LIBXML_NONET);
        $cells = [];
        // A line or a column a reference puts outside any sheet is null: nothing after it on its row is marked, and
        // no arithmetic runs on a number the file chose.
        $line = 0;
        $column = -1;
        while ($reader->read()) {
            if (\XMLReader::ELEMENT !== $reader->nodeType) {
                continue;
            }
            if ('row' === $reader->localName) {
                $reference = $reader->getAttribute('r');
                $line = null === $reference ? (null === $line ? null : $line + 1) : self::lineOf($reference);
                $column = -1;
            } elseif ('c' === $reader->localName) {
                $reference = $reader->getAttribute('r');
                $column = null === $reference ? (null === $column ? null : $column + 1) : self::columnOf($reference);
                $style = $reader->getAttribute('s');
                if (null !== $line && null !== $column && $line <= self::LAST_LINE && $column < self::COLUMNS && null !== $style && isset($styles[(int) $style])) {
                    $cells[$line][$column] = true;
                }
            }
        }
        $reader->close();

        return $cells;
    }

    /** « A1 » is column 0, « AB7 » column 27; null for more letters than a sheet's last column, « XFD », has. */
    private static function columnOf(string $reference): ?int
    {
        $letters = (string) preg_replace('/[^A-Z]/', '', strtoupper($reference));
        if ('' === $letters || \strlen($letters) > 3) {
            return null;
        }
        $column = 0;
        foreach (str_split($letters) as $letter) {
            $column = $column * 26 + \ord($letter) - 64;
        }

        return $column - 1;
    }

    /** A row's number as its reference writes it; null for one no sheet has. */
    private static function lineOf(string $reference): ?int
    {
        return 1 === preg_match('/^[1-9]\d{0,6}$/', $reference) && (int) $reference <= self::LAST_LINE ? (int) $reference : null;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Spreadsheet;

use OpenSpout\Reader\CSV\Options as CsvOptions;

/**
 * What a CSV actually is, rather than what it is assumed to be.
 *
 * "CSV" names no single format. Asked for one, French Excel writes semicolons in Windows-1252 with no byte-order
 * mark, because the comma is that locale's decimal separator; an export from a web application writes commas in
 * UTF-8. Read with the wrong pair, a file is not partly wrong — every line becomes one unreadable cell, and an
 * import would reject all of it for no reason its author could act on.
 *
 * The judgement is made on the first 8 KB, which bounds what a large file costs to open but leaves one case open: a
 * file whose opening pages are plain ASCII and whose accents begin further down is read as UTF-8, and those later
 * bytes arrive as they were written. That belongs to the import preview, which sees every cell and can say which
 * line it could not read, rather than to a sniffer that by then has already chosen.
 */
final readonly class CsvSniffer
{
    /** Tried in this order, so a file with equal counts is read the way the widest range of tools writes one. */
    private const array DELIMITERS = [',', ';', "\t", '|'];

    private const int SAMPLE_BYTES = 8192;

    public static function optionsFor(string $path): CsvOptions
    {
        $sample = @file_get_contents($path, length: self::SAMPLE_BYTES);
        if (false === $sample || '' === $sample) {
            return new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true);
        }

        return new CsvOptions(
            SHOULD_PRESERVE_EMPTY_ROWS: true,
            FIELD_DELIMITER: self::delimiterOf($sample),
            ENCODING: self::encodingOf($sample),
        );
    }

    /**
     * The separator that divides the header line into the most columns. The header is used rather than the whole file
     * because it is the one line certain to hold every column and no free text: an address holding a comma would
     * otherwise outvote the real separator.
     */
    private static function delimiterOf(string $sample): string
    {
        $header = strtok($sample, "\r\n");
        if (false === $header) {
            return self::DELIMITERS[0];
        }

        $best = self::DELIMITERS[0];
        $most = 0;
        foreach (self::DELIMITERS as $delimiter) {
            $count = \count(str_getcsv($header, $delimiter, '"', ''));
            if ($count > $most) {
                $most = $count;
                $best = $delimiter;
            }
        }

        return $best;
    }

    /**
     * UTF-8 when the bytes are valid UTF-8, Windows-1252 otherwise.
     *
     * The order matters and is not symmetric: every ASCII file is valid UTF-8 and is read identically either way, so
     * the question only ever arises on a file carrying accents, where valid UTF-8 byte sequences are vanishingly
     * unlikely to have been produced by a Windows-1252 writer. Windows-1252 rather than ISO-8859-1 because it is what
     * Excel on Windows writes, and it maps every byte, so nothing can fail to convert.
     */
    private static function encodingOf(string $sample): string
    {
        // A truncated sample must not be judged by a multi-byte character the 8 KB boundary cut in half.
        $whole = substr($sample, 0, strrpos($sample, "\n") ?: \strlen($sample));

        return mb_check_encoding($whole, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
    }
}

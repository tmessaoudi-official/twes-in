<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Spreadsheet;

use App\Shared\Application\Spreadsheet\TextEncoding;
use OpenSpout\Reader\CSV\Options as CsvOptions;

/**
 * What a CSV actually is, rather than what it is assumed to be.
 *
 * "CSV" names no single format. Asked for one, French Excel writes semicolons in Windows-1252 with no byte-order
 * mark, because the comma is that locale's decimal separator; Excel in Arabic writes Windows-1256; its « Texte
 * Unicode » writes UTF-16 with a mark; a web application writes commas in UTF-8. Read with the wrong pair, a file is
 * not partly wrong: every line becomes one unreadable cell, or its accents reach the database as bytes it refuses.
 *
 * The encoding is judged on the whole file, read a chunk at a time, so a file whose accents begin past its first
 * pages is read as what it is. The separator is judged on the header of the UTF-8 text the reader is given.
 */
final readonly class CsvSniffer
{
    /** Tried in this order, so a file with equal counts is read the way the widest range of tools writes one. */
    private const array DELIMITERS = [',', ';', "\t", '|'];

    private const int SAMPLE_BYTES = 8192;

    public const int CHUNK_BYTES = 65536;

    /**
     * Three letters in a row from the upper half of a Windows code page: an Arabic word in Windows-1256, where every
     * letter of the alphabet sits at 0xC1 and above. French writes its accents alone or two together (« créée »), and
     * a guillemet or a no-break space sits below 0xC0, so « MUÑOZ » or « « Économie » » never reads as Arabic.
     */
    private const string ARABIC_WORD = '/[\xC0-\xFF]{3}/';

    /** For a file already in UTF-8: the separator of its header. */
    public static function optionsFor(string $path): CsvOptions
    {
        $sample = @file_get_contents($path, length: self::SAMPLE_BYTES);
        if (false === $sample || '' === $sample) {
            return new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true);
        }

        return new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true, FIELD_DELIMITER: self::delimiterOf($sample));
    }

    /**
     * A byte-order mark's encoding; else UTF-8 when every byte of the file is valid UTF-8, which a Windows code page
     * with accents almost never is; else Windows-1256 when the file holds an Arabic word, Windows-1252 otherwise.
     */
    public static function encodingOf(string $path): TextEncoding
    {
        $file = @fopen($path, 'r');
        if (false === $file) {
            return TextEncoding::Utf8;
        }
        try {
            $start = (string) fread($file, 3);
            if (str_starts_with($start, "\xEF\xBB\xBF")) {
                return TextEncoding::Utf8;
            }
            if (str_starts_with($start, "\xFF\xFE") || str_starts_with($start, "\xFE\xFF")) {
                return TextEncoding::Utf16;
            }

            $utf8 = true;
            $arabic = false;
            $carried = $start;
            while (!feof($file)) {
                $carried .= (string) fread($file, self::CHUNK_BYTES);
                // A line end is never inside a multi-byte character, so a chunk judged up to its last one is whole.
                $end = strrpos($carried, "\n");
                if (false === $end) {
                    continue;
                }
                $whole = substr($carried, 0, $end + 1);
                $carried = substr($carried, $end + 1);
                $utf8 = $utf8 && mb_check_encoding($whole, 'UTF-8');
                $arabic = $arabic || 1 === preg_match(self::ARABIC_WORD, $whole);
                if (!$utf8 && $arabic) {
                    break;
                }
            }
            $utf8 = $utf8 && mb_check_encoding($carried, 'UTF-8');
            $arabic = $arabic || 1 === preg_match(self::ARABIC_WORD, $carried);
        } finally {
            fclose($file);
        }

        return match (true) {
            $utf8 => TextEncoding::Utf8,
            $arabic => TextEncoding::Windows1256,
            default => TextEncoding::Windows1252,
        };
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
}

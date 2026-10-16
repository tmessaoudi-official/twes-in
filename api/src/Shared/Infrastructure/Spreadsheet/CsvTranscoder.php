<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Spreadsheet;

use App\Shared\Application\Spreadsheet\TextEncoding;
use App\Shared\Application\Spreadsheet\UnreadableSpreadsheet;

/**
 * Writes a CSV out again in UTF-8, a chunk at a time, so the reader only ever splits UTF-8 text. Read as written, a
 * UTF-16 line is split on the delimiter's byte before it is converted, and « ج » (U+062C) holds the byte of a comma.
 *
 * mbstring converts Windows-1252, mapping the five bytes it leaves undefined to control characters rather than failing,
 * and UTF-16; it has no Windows-1256, which iconv converts.
 */
final readonly class CsvTranscoder
{
    /** @throws UnreadableSpreadsheet */
    public static function toUtf8(string $from, string $to, TextEncoding $encoding): void
    {
        $in = @fopen($from, 'r');
        $out = @fopen($to, 'w');
        if (false === $in || false === $out) {
            throw new UnreadableSpreadsheet(\sprintf('"%s" could not be read.', basename($from)));
        }
        try {
            $littleEndian = true;
            $carried = '';
            if (TextEncoding::Utf16 === $encoding) {
                $mark = (string) fread($in, 2);
                $littleEndian = "\xFE\xFF" !== $mark;
                $carried = "\xFF\xFE" === $mark || "\xFE\xFF" === $mark ? '' : $mark;
            }
            while (!feof($in)) {
                $chunk = $carried.fread($in, CsvSniffer::CHUNK_BYTES);
                $carried = '';
                if (TextEncoding::Utf16 === $encoding) {
                    [$chunk, $carried] = self::wholeUtf16($chunk, $littleEndian);
                }
                fwrite($out, self::converted($chunk, $encoding, $littleEndian));
            }
            if ('' !== $carried) {
                fwrite($out, self::converted($carried, $encoding, $littleEndian));
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * The chunk up to its last whole character, and what is carried to the next: an odd byte, or the first half of a
     * surrogate pair, which converted alone would become « ? ».
     *
     * @return array{string, string}
     */
    private static function wholeUtf16(string $chunk, bool $littleEndian): array
    {
        $length = \strlen($chunk) - \strlen($chunk) % 2;
        if ($length >= 2) {
            $high = \ord($chunk[$littleEndian ? $length - 1 : $length - 2]);
            if ($high >= 0xD8 && $high <= 0xDB) {
                $length -= 2;
            }
        }

        return [substr($chunk, 0, $length), substr($chunk, $length)];
    }

    /** @throws UnreadableSpreadsheet */
    private static function converted(string $chunk, TextEncoding $encoding, bool $littleEndian): string
    {
        $converted = match ($encoding) {
            TextEncoding::Utf8 => $chunk,
            TextEncoding::Windows1252 => mb_convert_encoding($chunk, 'UTF-8', 'Windows-1252'),
            TextEncoding::Windows1256 => iconv('CP1256', 'UTF-8', $chunk),
            TextEncoding::Utf16 => mb_convert_encoding($chunk, 'UTF-8', $littleEndian ? 'UTF-16LE' : 'UTF-16BE'),
        };
        if (false === $converted) {
            throw new UnreadableSpreadsheet(\sprintf('The file could not be read as %s.', $encoding->value));
        }

        return $converted;
    }
}

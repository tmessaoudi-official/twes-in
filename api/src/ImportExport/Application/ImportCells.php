<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * A cell read as the spreadsheets of the people filling it in write it: a French or Tunisian sheet writes « 1 234,500 »
 * and « 15/03/2026 », and a computed price comes out of Excel as 14.875000000000002. What is no number or no day is
 * passed on as written, for the rule that refuses it to say why.
 */
final class ImportCells
{
    /** Every space a keyboard or a spreadsheet sets thousands apart with: the no-break and the narrow no-break among them. */
    private const string SPACES = '/[\s\x{00A0}\x{202F}]+/u';

    private const string DECIMAL = '/^(-?[0-9]+)(?:\.([0-9]+))?$/D';

    /** How far a binary float may stray from the decimal it stands for, relative to it: Excel keeps 15 digits. */
    private const string FLOAT_NOISE = '0.000000001';

    /**
     * @param int $decimals how many decimals the column holds
     *
     * @throws RowRejected when the number is finer than the column holds
     */
    public static function decimal(ImportRecord $record, string $column, int $decimals): ?string
    {
        $cell = $record->value($column);
        if (null === $cell) {
            return null;
        }
        $written = str_replace(',', '.', (string) preg_replace(self::SPACES, '', $cell));
        if (1 !== preg_match(self::DECIMAL, $written, $parts) || !is_numeric($written)) {
            return $written;
        }
        if (\strlen($parts[2] ?? '') <= $decimals) {
            return $written;
        }
        // Zeros past the column's scale, and a binary float's noise, round away to nothing.
        $rounded = self::rounded($written, $decimals);
        $size = self::absolute($written);
        $tolerance = bcmul(self::FLOAT_NOISE, bccomp($size, '1', 40) > 0 ? $size : '1', 40);
        if (bccomp(self::absolute(bcsub($written, $rounded, 40)), $tolerance, 40) <= 0) {
            return rtrim(rtrim($rounded, '0'), '.');
        }

        throw new RowRejected($column, \sprintf('At most %d decimals.', $decimals), 'too_many_decimals', ['max' => $decimals]);
    }

    /** A day written day first, as French and Tunisian people write one, in the notation a date field keeps. */
    public static function day(string $cell): string
    {
        if (1 === preg_match('#^([0-9]{1,2})/([0-9]{1,2})/([0-9]{4})$#D', trim($cell), $parts) && checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])) {
            return \sprintf('%04d-%02d-%02d', $parts[3], $parts[2], $parts[1]);
        }

        return $cell;
    }

    /**
     * Half away from zero, as a person rounds.
     *
     * @param numeric-string $value
     *
     * @return numeric-string
     */
    private static function rounded(string $value, int $decimals): string
    {
        $half = bcdiv('5', bcpow('10', (string) ($decimals + 1), 0), $decimals + 1);

        return bccomp($value, '0', 40) < 0 ? bcsub($value, $half, $decimals) : bcadd($value, $half, $decimals);
    }

    /**
     * @param numeric-string $value
     *
     * @return numeric-string
     */
    private static function absolute(string $value): string
    {
        return bccomp($value, '0', 40) < 0 ? bcmul($value, '-1', 40) : $value;
    }
}

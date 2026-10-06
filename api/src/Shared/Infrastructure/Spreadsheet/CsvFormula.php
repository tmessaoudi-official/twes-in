<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Spreadsheet;

/**
 * A CSV cell that a spreadsheet would run as a formula, made text (OWASP's CSV injection advice): a cell beginning with
 * `=`, `+`, `-`, `@`, a tab or a carriage return is written behind a quote, which a spreadsheet shows as text and does
 * not evaluate. A plain negative amount is a number, not a formula, and stays as it is. XLSX needs none of this: every
 * cell there is written as a string cell, never as a formula.
 *
 * Reading undoes it, so an export imported again carries what was exported: a quote is taken off only where it stands
 * before one of those characters, which is where this put it.
 */
final class CsvFormula
{
    private const string STARTS_A_FORMULA = '/^[=+\-@\t\r]/';
    private const string NEGATIVE_NUMBER = '/^-\d+(\.\d+)?$/';

    public static function defused(string $value): string
    {
        if (1 !== preg_match(self::STARTS_A_FORMULA, $value) || 1 === preg_match(self::NEGATIVE_NUMBER, $value)) {
            return $value;
        }

        return "'".$value;
    }

    public static function restored(string $value): string
    {
        return str_starts_with($value, "'") && 1 === preg_match(self::STARTS_A_FORMULA, substr($value, 1))
            ? substr($value, 1)
            : $value;
    }
}

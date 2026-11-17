<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

use App\ImportExport\Application\Notation\DayOrder;
use App\ImportExport\Application\Notation\DecimalMark;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\CheckMark;

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

    private const string PERCENTAGE = '/^-?[0-9.]+%$/D';

    /** How far a binary float may stray from the decimal it stands for, relative to it: Excel keeps 15 digits. */
    private const string FLOAT_NOISE = '0.000000001';

    /**
     * @param int           $decimals    how many decimals the column holds
     * @param RowNotes|null $roundedInto where a number finer than the column holds is rounded and said, as a file may
     *                                   ask of a price (`decimals_rounded`); null refuses it, as every quantity is
     *
     * @throws RowRejected when the number is finer than the column holds and not to be rounded, is written as a percentage,
     *                     or writes the other decimal mark where only a guess could read it
     */
    public static function decimal(ImportRecord $record, string $column, int $decimals, ?RowNotes $roundedInto = null): ?string
    {
        $cell = $record->value($column);
        if (null === $cell) {
            return null;
        }
        $written = self::written($cell, $record->notation->decimals, $column);
        // A rate column holds 5 for 5 %: a percentage, typed or shown so by Excel, is refused rather than guessed at.
        if (1 === preg_match(self::PERCENTAGE, $written)) {
            throw new RowRejected($column, 'Write 5 for 5 %.', 'percent_sign');
        }
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
        if (null !== $roundedInto) {
            $kept = rtrim(rtrim($rounded, '0'), '.');
            $roundedInto->note($column, 'decimals_rounded', ['written' => $written, 'kept' => $kept]);

            return $kept;
        }

        throw new RowRejected($column, \sprintf('At most %d decimals.', $decimals), 'too_many_decimals', ['max' => $decimals]);
    }

    /**
     * A custom number field's cell: a number of no fixed scale, kept as a JSON number, never money; null when it is none.
     *
     * @throws RowRejected when it writes the other decimal mark where only a guess could read it
     */
    public static function number(ImportRecord $record, string $column): int|float|null
    {
        $cell = $record->value($column);
        if (null === $cell) {
            return null;
        }
        $written = self::written($cell, $record->notation->decimals, $column);
        if (!is_numeric($written)) {
            return null;
        }

        return 1 === preg_match('/^-?\d+$/D', $written) ? (int) $written : (float) $written; // float: a custom number field is stored as a JSON number, never money
    }

    /**
     * The number a cell writes with a point and no thousands apart, in the file's notation; what is no number is passed
     * on as written. Automatic reads a comma as a point. A mark named reads the other one as thousands set apart only
     * beyond doubt, groups of three with the decimal mark or a second group beside them: « 12.500 » in a comma file may
     * be twelve thousand or a point file's twelve and a half, and is refused rather than read a thousand times larger.
     *
     * @throws RowRejected when the other mark stands where only a guess could read it
     */
    private static function written(string $cell, DecimalMark $mark, string $column): string
    {
        $spaced = (string) preg_replace(self::SPACES, '', $cell);
        if (DecimalMark::Automatic === $mark || 1 !== preg_match('/^-?[0-9.,]+$/D', $spaced)) {
            // What is no number, a percentage among them, is read as before, for the rule that refuses it.
            return str_replace(',', '.', $spaced);
        }
        [$decimal, $grouping] = DecimalMark::Point === $mark ? ['.', ','] : [',', '.'];
        if (str_contains($spaced, $grouping)) {
            $grouped = \sprintf('/^-?[0-9]{1,3}(?:%s[0-9]{3})+(?:%s[0-9]+)?$/D', preg_quote($grouping, '/'), preg_quote($decimal, '/'));
            if (1 !== preg_match($grouped, $spaced) || (1 === substr_count($spaced, $grouping) && !str_contains($spaced, $decimal))) {
                throw new RowRejected($column, \sprintf('This company\'s CSV files write decimals with « %s »: write 12%s5, or 12500 with no separator.', $decimal, $decimal), 'decimal_mark', ['mark' => $decimal]);
            }
            $spaced = str_replace($grouping, '', $spaced);
        }

        return str_replace($decimal, '.', $spaced);
    }

    /**
     * « À vérifier » as a row writes it, the same for every subject: its note sets the mark with that note, and a blank
     * cell keeps the mark as it is.
     *
     * @throws RowRejected when the note is longer than a mark keeps
     */
    public static function checkMark(ImportRecord $record, string $column): ?CheckMark
    {
        $note = $record->value($column);
        if (null === $note) {
            return null;
        }
        $refusal = CheckMark::noteRefusal($note);
        if (null !== $refusal) {
            throw new RowRejected($column, $refusal->message, $refusal->reason, $refusal->params);
        }

        return new CheckMark(true, $note);
    }

    /**
     * A day written between slashes in the file's order, day first by default as French and Tunisian people write one, in
     * the notation a date field keeps; anything else, a day already so written among them, is left as it is.
     */
    public static function day(string $cell, DayOrder $order = DayOrder::DayFirst): string
    {
        $pattern = DayOrder::YearFirst === $order ? '#^([0-9]{4})/([0-9]{1,2})/([0-9]{1,2})$#D' : '#^([0-9]{1,2})/([0-9]{1,2})/([0-9]{4})$#D';
        if (1 !== preg_match($pattern, TypedText::trimmed($cell), $parts)) {
            return $cell;
        }
        [$year, $month, $day] = match ($order) {
            DayOrder::DayFirst => [$parts[3], $parts[2], $parts[1]],
            DayOrder::MonthFirst => [$parts[3], $parts[1], $parts[2]],
            DayOrder::YearFirst => [$parts[1], $parts[2], $parts[3]],
        };

        return checkdate((int) $month, (int) $day, (int) $year) ? \sprintf('%04d-%02d-%02d', $year, $month, $day) : $cell;
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

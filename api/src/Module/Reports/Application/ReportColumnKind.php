<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

/**
 * What a column holds, which says how the screen writes it and whether a total adds it up. Amounts and quantities are
 * decimal strings, a count and a number of days integers, a day `YYYY-MM-DD`; a label is text, written as the document wrote it, or a key the
 * screen translates.
 */
enum ReportColumnKind: string
{
    case Label = 'label';
    /** A key the screen translates under the report's texts: a payment method, an age bracket. */
    case Choice = 'choice';
    case Day = 'day';
    case Amount = 'amount';
    case Quantity = 'quantity';
    case Count = 'count';
    /** How many days a span lasted, such as how long a product has not sold. */
    case Days = 'days';

    /**
     * A figure the total row adds up: a sum of amounts or counts is meaningful, a sum of days or names is not, nor is a
     * sum of quantities, whose rows are other goods in other units, nor of spans, which each row measured on its own.
     */
    public function adds(): bool
    {
        return match ($this) {
            self::Amount, self::Count => true,
            self::Label, self::Choice, self::Day, self::Quantity, self::Days => false,
        };
    }

    /** A figure the period counted, which a row known only from the period compared has none of. */
    public function counts(): bool
    {
        return match ($this) {
            self::Amount, self::Quantity, self::Count, self::Days => true,
            self::Label, self::Choice, self::Day => false,
        };
    }
}

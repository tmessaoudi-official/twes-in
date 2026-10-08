<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Application;

use App\Shared\Domain\InvalidFilter;

/**
 * The days an accountant's file covers, both included, in order and no more than a year apart, so a file is always a
 * period the books can be checked against.
 */
final readonly class JournalPeriod
{
    /** A leap year's days: a whole year, never more. */
    public const int LONGEST = 366;

    /** @throws InvalidFilter */
    public function __construct(public \DateTimeImmutable $from, public \DateTimeImmutable $to)
    {
        if ($from > $to) {
            throw new InvalidFilter('from', 'The period starts on or before the day it ends.');
        }
        if ((int) $from->diff($to)->days >= self::LONGEST) {
            throw new InvalidFilter('to', 'A period is a year at most.');
        }
    }
}

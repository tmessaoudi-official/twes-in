<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Notation;

/**
 * How a file writes its numbers and days. A CSV holds text, so it is read in the company's notation (import.decimal_mark,
 * import.day_order); a workbook's numbers and days are cells of their own the reader writes out with a point and as
 * 2026-03-15, so a workbook is read in the default notation, which reads both.
 */
final readonly class ImportNotation
{
    public function __construct(
        public DecimalMark $decimals = DecimalMark::Automatic,
        public DayOrder $days = DayOrder::DayFirst,
    ) {
    }
}

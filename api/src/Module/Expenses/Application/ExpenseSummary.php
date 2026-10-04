<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application;

/** The home page's expenses figure: what was recorded so far this month and over the same days of last month. */
final readonly class ExpenseSummary
{
    public function __construct(
        public string $currency,
        public int $currencyScale,
        public string $today,
        public string $month,
        public string $lastMonth,
    ) {
    }
}

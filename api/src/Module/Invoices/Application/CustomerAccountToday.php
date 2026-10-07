<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/**
 * A customer's running account at the end of the company's today (RunningAccount). Amounts are decimal strings at the
 * currency's scale; `oldestOverdueDays` is how many days the longest-late invoice is past its due day, null when none is.
 */
final readonly class CustomerAccountToday
{
    public function __construct(
        public string $customerId,
        public string $currency,
        public int $currencyScale,
        public string $day,
        public string $balance,
        public string $overdue,
        public int $overdueCount,
        public ?int $oldestOverdueDays,
        public string $onAccount,
        public string $owed,
        public string $creditLimit,
        public bool $overCreditLimit,
    ) {
    }
}

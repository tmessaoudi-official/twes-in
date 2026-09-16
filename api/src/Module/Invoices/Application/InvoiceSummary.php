<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/**
 * What the home page shows of a company's invoices on its own day: what is still to collect and how late, what was
 * collected month by month, the VAT its documents charged this month, and the invoices to chase first. Amounts are
 * decimal strings at the currency's scale; a day count is positive when late, negative when still ahead.
 */
final readonly class InvoiceSummary
{
    /**
     * @param list<array{bucket: string, amount: string, count: int}>                                                                 $aging     not yet due, then 1–15, 16–30, 31–45 and over 45 days late
     * @param list<array{invoiceId: string, number: string, customerName: string, dueDate: string, amountDue: string, daysLate: int}> $toChase   the latest first, then the soonest due, at most four
     * @param list<array{month: string, amount: string}>                                                                              $collected the payments of the last six months, the oldest first, this month last
     * @param list<array{code: string, rate: string, amount: string}>                                                                 $vat       by tax, the highest rate first
     */
    public function __construct(
        public string $currency,
        public int $currencyScale,
        public string $today,
        public string $outstanding,
        public string $notYetDue,
        public string $overdue,
        public int $overdueCount,
        public ?int $oldestOverdueDays,
        public array $aging,
        public array $toChase,
        public int $toChaseCount,
        public string $toChaseAmount,
        public array $collected,
        public array $vat,
        public string $vatTotal,
    ) {
    }
}

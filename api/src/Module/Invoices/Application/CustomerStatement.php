<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/**
 * A customer's account over a period: what they owed at its start, what happened in it and what they owed at its end.
 * Amounts are decimal strings at the currency's scale; a line carries what the customer owed after it.
 */
final readonly class CustomerStatement
{
    /**
     * @param list<array{day: string, kind: string, number: string, documentId: string, reference: ?string, debit: string, credit: string, balance: string}> $lines
     */
    public function __construct(
        public string $customerId,
        public string $customerName,
        public string $customerNumber,
        public string $currency,
        public int $currencyScale,
        public string $from,
        public string $to,
        public string $openingBalance,
        public string $totalDebit,
        public string $totalCredit,
        public string $closingBalance,
        public array $lines,
    ) {
    }
}

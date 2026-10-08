<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Application;

/**
 * An issued invoice or credit note as the sales journal reads it, on its issue day. A credit note's amounts are
 * negative, so that a column of the period sums to what was sold.
 */
final readonly class SalesDocument
{
    /**
     * @param string            $type                `invoice` or `credit_note`
     * @param string            $customerIdentifiers the registration numbers printed on it, `key=value` separated by `; `
     * @param list<DocumentTax> $taxes               every tax on a rate, then every fixed charge
     */
    public function __construct(
        public \DateTimeImmutable $issueDate,
        public string $number,
        public string $type,
        public string $customerNumber,
        public string $customerName,
        public string $customerIdentifiers,
        public array $taxes,
        public string $totalNet,
        public string $total,
    ) {
    }
}

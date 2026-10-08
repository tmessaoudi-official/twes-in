<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Application;

/** A payment received on an invoice, as the payments journal reads it on the day it was received. */
final readonly class PaymentEntry
{
    public function __construct(
        public \DateTimeImmutable $date,
        public string $invoiceNumber,
        public string $customerNumber,
        public string $customerName,
        public string $method,
        public string $reference,
        public string $amount,
    ) {
    }
}

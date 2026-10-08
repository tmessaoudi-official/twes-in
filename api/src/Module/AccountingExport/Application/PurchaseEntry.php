<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Application;

/** An expense entered in the books, as the purchases journal reads it on its own day. */
final readonly class PurchaseEntry
{
    public function __construct(
        public \DateTimeImmutable $date,
        public string $reference,
        public string $vendor,
        public string $description,
        public string $category,
        public string $taxCode,
        public ?string $taxRate,
        public string $net,
        public string $tax,
        public string $gross,
        public string $withholding,
        public string $status,
        public ?\DateTimeImmutable $paidOn,
        public string $paymentMethod,
    ) {
    }
}

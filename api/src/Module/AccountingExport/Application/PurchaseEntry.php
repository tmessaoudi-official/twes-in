<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Application;

/** An expense entered in the books, as the purchases journal reads it on its own day, with every tax its bill carries. */
final readonly class PurchaseEntry
{
    /**
     * @param list<DocumentTax> $taxes the rates on their bases, then the fixed charges
     * @param string            $tax   what the rates come to
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public string $reference,
        public string $vendor,
        public string $description,
        public string $category,
        public array $taxes,
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

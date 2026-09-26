<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

/**
 * The document totals (BG-22): lines (BT-106), allowances (BT-107), without VAT (BT-109), VAT (BT-110), with VAT
 * (BT-112) and due (BT-115). Nothing is prepaid and nothing is rounded: the file says what the document said at issue.
 */
final readonly class CiiTotals
{
    public function __construct(
        public string $lineTotal,
        public string $allowanceTotal,
        public string $taxBasisTotal,
        public string $taxTotal,
        public string $grandTotal,
        public string $duePayable,
    ) {
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Fiscal\Application\Inventory\FiscalRecords;

/** The orders numbered when sent, with their lines, and the goods received against them, which moved the stock. */
final readonly class PurchaseFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [
            FiscalRecords::fiscal('purchases.orders_numbered', 'purchase_order', 'number IS NOT NULL', ['purchase_order_line'], numberColumn: 'number', dateColumn: 'issue_date'),
            FiscalRecords::fiscal('purchases.goods_receipts', 'goods_receipt', parts: ['goods_receipt_line'], dateColumn: 'received_on'),
        ];
    }

    public function notFiscal(): array
    {
        return [];
    }
}

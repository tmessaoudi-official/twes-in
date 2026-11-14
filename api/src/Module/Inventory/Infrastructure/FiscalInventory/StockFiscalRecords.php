<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Fiscal\Application\Inventory\FiscalRecords;

/** Every stock movement: the stock on hand and its value are only ever summed from them, and none is ever deleted. */
final readonly class StockFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [
            FiscalRecords::fiscal('inventory.stock_movements', 'stock_movement', dateColumn: 'at'),
        ];
    }

    public function notFiscal(): array
    {
        return [
            'stock_move_failure' => 'a stock move that failed and waits to be replayed; its document is counted where it was issued, its movements once replayed',
        ];
    }
}

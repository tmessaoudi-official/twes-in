<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Fiscal\Application\Inventory\FiscalRecords;

/** A product's cost history, which values its stock: kept with the movements that changed it. */
final readonly class ProductFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [
            FiscalRecords::kept('products.cost_changes', 'product_cost_change'),
        ];
    }

    public function notFiscal(): array
    {
        return [];
    }
}

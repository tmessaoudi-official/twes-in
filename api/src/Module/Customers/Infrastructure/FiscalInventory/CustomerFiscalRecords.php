<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;

/** No customer is a fiscal record: an issued document keeps its customer as it stood, in its own snapshot. */
final readonly class CustomerFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [];
    }

    public function notFiscal(): array
    {
        return [
            'customer' => 'its number is the customer\'s own code, no document\'s; an issued document keeps the customer as it stood',
        ];
    }
}

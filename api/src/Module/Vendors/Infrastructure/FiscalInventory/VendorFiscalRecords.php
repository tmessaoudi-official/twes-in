<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;

/** No vendor is a fiscal record: an order sent keeps its vendor as it stood, in its own snapshot. */
final readonly class VendorFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [];
    }

    public function notFiscal(): array
    {
        return [
            'vendor' => 'its number is the vendor\'s own code, no document\'s; an order sent keeps the vendor as it stood',
        ];
    }
}

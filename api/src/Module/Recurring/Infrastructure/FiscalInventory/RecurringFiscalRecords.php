<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;

/** A recurring invoice is a schedule: what it issued is counted as invoices. */
final readonly class RecurringFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [];
    }

    public function notFiscal(): array
    {
        return [
            'recurring_invoice' => 'a schedule naming its model and its last invoice; what it issued is counted as invoices',
        ];
    }
}

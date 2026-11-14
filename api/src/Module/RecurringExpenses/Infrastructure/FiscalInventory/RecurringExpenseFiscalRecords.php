<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;

/** A recurring expense is a schedule: what it recorded is counted as expenses. */
final readonly class RecurringExpenseFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [];
    }

    public function notFiscal(): array
    {
        return [
            'recurring_expense' => 'a schedule naming its model and its last expense; what it recorded is counted as expenses',
        ];
    }
}

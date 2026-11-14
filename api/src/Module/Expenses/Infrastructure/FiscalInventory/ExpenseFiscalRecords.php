<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Fiscal\Application\Inventory\FiscalRecords;

/**
 * The expenses recorded or paid, with their lines and taxes: the supporting documents of what the company deducts, and
 * of the withholding it declares.
 */
final readonly class ExpenseFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [
            FiscalRecords::fiscal('expenses.recorded', 'expense', "status <> 'draft'", ['expense_line', 'expense_line_tax', 'expense_tax'], dateColumn: 'expense_date'),
        ];
    }

    public function notFiscal(): array
    {
        return [];
    }
}

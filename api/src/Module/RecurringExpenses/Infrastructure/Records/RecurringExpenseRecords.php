<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Infrastructure\Records;

use App\Module\Expenses\Infrastructure\ApiPlatform\Security\ExpensePermission;
use App\Module\RecurringExpenses\Infrastructure\Module\RecurringExpensesModule;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordSubject;

/**
 * A recurring expense is a schedule, deleted freely and put back within the day; the drafts it made stay. It holds no
 * word of its own, so it reads as its model expense's description.
 */
final readonly class RecurringExpenseRecords implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [new RecordSubject('recurring_expense', ExpensePermission::WRITE, RecurringExpensesModule::KEY, [], labelTable: 'expense', labelKey: 'model_expense_id', labelTableColumns: ['description'])];
    }

    public function references(): array
    {
        return [];
    }
}

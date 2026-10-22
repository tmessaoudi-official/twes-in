<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Records;

use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Domain\ValueObject\ExpenseStatus;
use App\Module\Expenses\Infrastructure\ApiPlatform\Security\ExpensePermission;
use App\Module\Expenses\Infrastructure\Module\ExpensesModule;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;

/**
 * An expense names its vendor. A recorded expense is in the books and keeps no copy of the vendor, so a merge leaves it
 * naming the vendor that went, which stays as the kept one's alias; a draft moves to the vendor kept. A draft is deleted
 * as every draft is, its lines, taxes and receipts with it, and put back within the day; a recorded one never is.
 */
final readonly class ExpenseRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        // A draft has no number: it reads as what it bought.
        return [new RecordSubject('expense', ExpensePermission::WRITE, ExpensesModule::KEY, ['description'], whereColumn: 'status', whereValues: [ExpenseStatus::Draft->value])];
    }

    public function references(): array
    {
        return [
            RecordReference::owned('expense_line', 'expense_id', 'expense', RecordMerge::Ignored, 'A line is part of its draft: it goes with it and comes back with it.'),
            RecordReference::owned('expense_line_tax', 'line_id', 'expense', RecordMerge::Ignored, 'A line\'s tax is part of its line: it goes before it and comes back after it.')
                ->below('expense_line', 'expense_id'),
            RecordReference::owned('expense_tax', 'expense_id', 'expense', RecordMerge::Ignored, 'A document tax is part of its draft: it goes with it and comes back with it.'),
            RecordReference::owned('attachment', 'entity_id', 'expense', RecordMerge::Ignored, 'A receipt attached to a draft goes with it, and is let go with it once the delete is final.')
                ->where('entity_type', ManageExpenses::ENTITY_TYPE),
            RecordReference::uses('expense', 'vendor_id', 'vendor', RecordMerge::Moves, 'An expense names the vendor it was bought from.')
                ->movingOnlyWhere('status', [ExpenseStatus::Draft->value])
                ->transactingAt('updated_at'),
        ];
    }
}

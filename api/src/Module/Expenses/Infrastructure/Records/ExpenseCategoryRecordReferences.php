<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Records;

use App\Module\Expenses\Infrastructure\ApiPlatform\Security\ExpensePermission;
use App\Module\Expenses\Infrastructure\Module\ExpensesModule;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\MergedAway;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;

/**
 * An expense category is deleted while it files no expense, holds no subcategory and is no vendor's default, restored,
 * and merged into another; whoever writes expenses does it. A category only files: merged, what it filed goes under the
 * category kept, a recorded expense included, since filing is no figure the books printed.
 */
final readonly class ExpenseCategoryRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [new RecordSubject('expense_category', ExpensePermission::WRITE, ExpensesModule::KEY, ['name'], MergedAway::Removed)];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('expense', 'category_id', 'expense_category', RecordMerge::Moves, 'An expense is filed under its category.'),
            RecordReference::uses('expense_category', 'parent_id', 'expense_category', RecordMerge::Moves, 'A subcategory sits under its category.'),
        ];
    }
}

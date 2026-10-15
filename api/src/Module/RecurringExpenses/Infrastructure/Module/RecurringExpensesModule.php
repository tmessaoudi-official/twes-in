<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Infrastructure\Module;

use App\Module\Expenses\Infrastructure\Module\ExpensesModule;
use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/**
 * « Dépenses récurrentes »: an expense drafted again on a schedule, for a person to check and record. It copies
 * expenses, so it needs them, and is read and written under their permissions: what it makes is an expense.
 */
final readonly class RecurringExpensesModule implements DeclaresModule
{
    public const string KEY = 'recurring_expenses';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::KEY, 'modules.recurring_expenses', [ExpensesModule::KEY]);
    }
}

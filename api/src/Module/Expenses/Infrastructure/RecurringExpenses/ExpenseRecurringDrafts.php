<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\RecurringExpenses;

use App\Module\Expenses\Application\ExpenseNotFound;
use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Domain\InvalidExpense;
use App\Module\RecurringExpenses\Application\RecurringExpenseDrafts;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * A recurring expense's occurrence, drafted as a copy of its model by the expenses' own use case, through the same
 * checks as one typed: a model gone, or naming what a new expense may no longer name, is not copied.
 */
final readonly class ExpenseRecurringDrafts implements RecurringExpenseDrafts
{
    public function __construct(private ManageExpenses $expenses)
    {
    }

    public function draftFrom(Company $company, Uuid $modelExpenseId, Uuid $recurringExpenseId, \DateTimeImmutable $day, bool $amountToCheck): ?Uuid
    {
        try {
            return $this->expenses->draftFromModel($company, $modelExpenseId, $day, $amountToCheck, ['recurringExpenseId' => $recurringExpenseId->toRfc4122(), 'dueOn' => $day->format('Y-m-d')])->getId();
        } catch (ExpenseNotFound|InvalidExpense) {
            return null;
        }
    }
}

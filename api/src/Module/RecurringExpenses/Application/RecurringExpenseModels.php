<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Application;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** The expenses a recurring expense copies, read from the expenses, which answer this port. */
interface RecurringExpenseModels
{
    /** The company's expense, or null where it has none of that id. */
    public function describe(Company $company, Uuid $expenseId): ?RecurringExpenseModel;

    /**
     * Several at once, keyed by id; an id the company has no expense of is left out.
     *
     * @param list<Uuid> $expenseIds
     *
     * @return array<string, RecurringExpenseModel>
     */
    public function describeAll(Company $company, array $expenseIds): array;
}

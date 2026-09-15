<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

use Symfony\Component\Uid\Uuid;

interface ExpenseRepository
{
    /** @return list<Expense> one company's expenses, the latest day first */
    public function ofCompany(Uuid $companyId): array;

    /** Null for an expense that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Expense;

    public function save(Expense $expense): void;

    public function remove(Expense $expense): void;
}

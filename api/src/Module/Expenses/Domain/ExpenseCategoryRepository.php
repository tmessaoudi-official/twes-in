<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

use Symfony\Component\Uid\Uuid;

interface ExpenseCategoryRepository
{
    /** @return list<ExpenseCategory> one company's categories, by name */
    public function ofCompany(Uuid $companyId): array;

    /** Null for a category that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?ExpenseCategory;

    public function ofNameInCompany(string $name, Uuid $companyId): ?ExpenseCategory;

    public function save(ExpenseCategory $category): void;
}

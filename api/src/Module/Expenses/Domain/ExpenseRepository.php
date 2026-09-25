<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

interface ExpenseRepository
{
    /** @return list<Expense> one company's expenses, the latest day first */
    public function ofCompany(Uuid $companyId): array;

    /**
     * One page of a company's expenses, searched, narrowed and sorted by the database.
     *
     * @return Page<Expense>
     */
    public function search(Uuid $companyId, ExpenseSearch $search, PageRequest $page): Page;

    /**
     * A company's expenses paid from one day included to another excluded, by day of payment, then as they were
     * written down.
     *
     * @return list<Expense>
     */
    public function paidBetween(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): array;

    /** Null for an expense that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Expense;

    public function save(Expense $expense): void;

    public function remove(Expense $expense): void;
}

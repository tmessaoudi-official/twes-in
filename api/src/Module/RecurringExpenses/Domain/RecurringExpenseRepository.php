<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Domain;

use Symfony\Component\Uid\Uuid;

interface RecurringExpenseRepository
{
    public function save(RecurringExpense $recurring): void;

    public function remove(RecurringExpense $recurring): void;

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?RecurringExpense;

    /** The same, locked for the rest of the transaction, read again from the database. */
    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?RecurringExpense;

    /**
     * The company's recurring expenses, the next to draft first and the ended last.
     *
     * @return list<RecurringExpense>
     */
    public function ofCompany(Uuid $companyId): array;

    /**
     * The ones not paused whose next occurrence falls on or before the day.
     *
     * @return list<Uuid>
     */
    public function dueOn(Uuid $companyId, \DateTimeImmutable $day): array;
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Application;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** Drafting an occurrence: a copy of the model as a new draft expense, which the expenses write and audit. */
interface RecurringExpenseDrafts
{
    /**
     * The draft's id, or null when the model can no longer be copied: gone, or naming what a new expense may no longer
     * name (a retired rate or category, a vendor while Vendors is off).
     *
     * @param \DateTimeImmutable $day           the occurrence's day, the draft's own
     * @param bool               $amountToCheck whether the draft's amount is to be checked before it is recorded
     */
    public function draftFrom(Company $company, Uuid $modelExpenseId, Uuid $recurringExpenseId, \DateTimeImmutable $day, bool $amountToCheck): ?Uuid;
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Infrastructure\Erasure;

use App\Erasure\Application\DeclaresErasure;
use App\Erasure\Application\ErasureReference;

/** A recurring expense copies an expense, a draft as well as a recorded one: erasing drafts leaves that one in place. */
final readonly class RecurringExpenseErasureReferences implements DeclaresErasure
{
    public function steps(): array
    {
        return [];
    }

    public function references(): array
    {
        return [
            ErasureReference::keep('recurring_expense', 'model_expense_id', 'expense', 'The expense a recurring expense copies stays while it does.'),
            ErasureReference::keep('recurring_expense', 'last_expense_id', 'expense', 'The last expense a recurring expense made stays while it names it.'),
        ];
    }

    public function files(): array
    {
        return [];
    }
}

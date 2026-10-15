<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Infrastructure\Inbox;

use App\Inbox\Application\DeclaresNotificationKinds;
use App\Inbox\Application\NotificationAudience;
use App\Inbox\Application\NotificationKind;
use App\Module\RecurringExpenses\Application\RunRecurringExpenses;
use App\Module\RecurringExpenses\Infrastructure\Module\RecurringExpensesModule;

/** What the recurring expenses tell whoever writes expenses: a draft to check, a recurrence stopped. */
final readonly class RecurringExpenseNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        return [
            new NotificationKind(RunRecurringExpenses::DRAFTED, NotificationAudience::Company, RecurringExpensesModule::KEY, RunRecurringExpenses::PERMISSION),
            new NotificationKind(RunRecurringExpenses::STOPPED, NotificationAudience::Company, RecurringExpensesModule::KEY, RunRecurringExpenses::PERMISSION),
        ];
    }
}

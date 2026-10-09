<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\Inbox;

use App\Inbox\Application\DeclaresNotificationKinds;
use App\Inbox\Application\NotificationAudience;
use App\Inbox\Application\NotificationKind;
use App\Module\Recurring\Application\RunRecurringInvoices;
use App\Module\Recurring\Infrastructure\Module\RecurringModule;

/** What the recurring invoices tell whoever writes invoices: a draft made, a recurrence stopped. */
final readonly class RecurringNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        return [
            new NotificationKind(RunRecurringInvoices::DRAFTED, NotificationAudience::Company, RecurringModule::KEY, RunRecurringInvoices::PERMISSION),
            new NotificationKind(RunRecurringInvoices::STOPPED, NotificationAudience::Company, RecurringModule::KEY, RunRecurringInvoices::PERMISSION),
        ];
    }
}

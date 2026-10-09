<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Inbox;

use App\Inbox\Application\DeclaresNotificationKinds;
use App\Inbox\Application\NotificationAudience;
use App\Inbox\Application\NotificationKind;
use App\Module\Invoices\Application\RemindLateInvoices;
use App\Module\Invoices\Application\TellCreditWatchers;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;

/** What invoicing tells whoever issues invoices: a customer over its credit limit, a reminder due, a late fee drafted. */
final readonly class InvoiceNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        return [
            new NotificationKind(TellCreditWatchers::LIMIT_PASSED, NotificationAudience::Company, InvoicesModule::KEY, TellCreditWatchers::PERMISSION),
            new NotificationKind(RemindLateInvoices::REMINDER_DUE, NotificationAudience::Company, InvoicesModule::KEY, RemindLateInvoices::PERMISSION),
            new NotificationKind(RemindLateInvoices::LATE_FEE_DRAFTED, NotificationAudience::Company, InvoicesModule::KEY, RemindLateInvoices::PERMISSION),
        ];
    }
}

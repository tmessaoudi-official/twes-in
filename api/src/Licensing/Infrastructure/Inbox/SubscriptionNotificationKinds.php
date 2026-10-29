<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\Inbox;

use App\Inbox\Application\DeclaresNotificationKinds;
use App\Inbox\Application\NotificationAudience;
use App\Inbox\Application\NotificationKind;
use App\Licensing\Application\ManagePayments;
use App\Licensing\Application\ManageSubscriptions;
use App\Licensing\Application\TellLapsingSubscriptions;
use App\Tenancy\Domain\Model\Role;

/**
 * A payment declared tells the operators, who decide it; how it was decided tells the company's owners. Both have a
 * mail of their own (SubscriptionMailer), so neither is mailed again as a notification. Terms an operator set or
 * stopped tell the owners too, by the notification's own mail, and so does the end of what they paid for drawing near.
 */
final readonly class SubscriptionNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        return [
            new NotificationKind(ManagePayments::NOTIFICATION_DECLARED, NotificationAudience::Platform, mailed: false),
            new NotificationKind(ManagePayments::NOTIFICATION_DECIDED, NotificationAudience::Company, role: Role::OWNER, mailed: false),
            new NotificationKind(ManageSubscriptions::SET, NotificationAudience::Company, role: Role::OWNER),
            new NotificationKind(ManageSubscriptions::STOPPED, NotificationAudience::Company, role: Role::OWNER),
            new NotificationKind(TellLapsingSubscriptions::LAPSING, NotificationAudience::Company, role: Role::OWNER, days: ['covered_until', 'grace_until']),
        ];
    }
}

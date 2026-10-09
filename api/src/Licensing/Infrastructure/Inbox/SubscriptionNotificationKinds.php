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
use App\Tenancy\Domain\Role;

/**
 * A payment declared tells the operators, who decide it; how it was decided tells the company's owners. Both have a
 * mail of their own (SubscriptionMailer), so neither is mailed again as a notification.
 */
final readonly class SubscriptionNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        return [
            new NotificationKind(ManagePayments::NOTIFICATION_DECLARED, NotificationAudience::Platform, mailed: false),
            new NotificationKind(ManagePayments::NOTIFICATION_DECIDED, NotificationAudience::Company, role: Role::OWNER, mailed: false),
        ];
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Installation\Infrastructure\Inbox;

use App\Inbox\Application\DeclaresNotificationKinds;
use App\Inbox\Application\NotificationAudience;
use App\Inbox\Application\NotificationKind;
use App\Installation\Application\TellInstallation;
use App\Tenancy\Domain\Model\Role;

/**
 * A release out and a moved address are each company's owners' to know; the rest is the operators'. None is mailed from here: the
 * installation script mails its own alerts, which must leave even when the application is what is down.
 */
final readonly class InstallationNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        // A moved address reaches the operators too, with no company: their bell offers it among the platform's.
        $owners = [TellInstallation::UPDATE_AVAILABLE, TellInstallation::ADDRESS_MOVED];
        $kinds = [];
        foreach ($owners as $type) {
            $kinds[] = new NotificationKind($type, NotificationAudience::Company, role: Role::OWNER, mailed: false);
        }
        foreach (TellInstallation::kinds() as $type) {
            if (!\in_array($type, $owners, true)) {
                $kinds[] = new NotificationKind($type, NotificationAudience::Platform, mailed: false);
            }
        }

        return $kinds;
    }
}

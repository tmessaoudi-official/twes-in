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
 * A release out, a moved address and a security release applied are each company's owners' to know; the rest is the
 * operators'. The installation script mails its own alerts, which must leave even when the application is what is down,
 * to the developer; a security release applied is mailed to the owners from here too, since it came outside the window
 * they chose, once the application it updated answers.
 */
final readonly class InstallationNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        // A moved address and a security release reach the operators too, with no company: their bell offers them among
        // the platform's.
        $owners = [TellInstallation::UPDATE_AVAILABLE, TellInstallation::ADDRESS_MOVED, TellInstallation::SECURITY_UPDATED];
        $kinds = [];
        foreach ($owners as $type) {
            $kinds[] = new NotificationKind($type, NotificationAudience::Company, role: Role::OWNER, mailed: TellInstallation::SECURITY_UPDATED === $type);
        }
        foreach (TellInstallation::kinds() as $type) {
            if (!\in_array($type, $owners, true)) {
                $kinds[] = new NotificationKind($type, NotificationAudience::Platform, mailed: false);
            }
        }

        return $kinds;
    }
}

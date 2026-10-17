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
 * A release out is each company's owners' to know; the rest is the operators'. None is mailed from here: the
 * installation script mails its own alerts, which must leave even when the application is what is down.
 */
final readonly class InstallationNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        $kinds = [new NotificationKind(TellInstallation::UPDATE_AVAILABLE, NotificationAudience::Company, role: Role::OWNER, mailed: false)];
        foreach (TellInstallation::kinds() as $type) {
            if (TellInstallation::UPDATE_AVAILABLE !== $type) {
                $kinds[] = new NotificationKind($type, NotificationAudience::Platform, mailed: false);
            }
        }

        return $kinds;
    }
}

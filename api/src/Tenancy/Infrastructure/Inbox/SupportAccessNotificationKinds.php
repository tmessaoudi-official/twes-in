<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Inbox;

use App\Inbox\Application\DeclaresNotificationKinds;
use App\Inbox\Application\NotificationAudience;
use App\Inbox\Application\NotificationKind;
use App\Tenancy\Application\Support\ManageSupportAccess;
use App\Tenancy\Domain\Role;

/** « Accès support » opened or closed early by one owner: told to the others, whose company it is as much. */
final readonly class SupportAccessNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        return [
            new NotificationKind(ManageSupportAccess::GRANTED, NotificationAudience::Company, role: Role::OWNER, moments: ['until']),
            new NotificationKind(ManageSupportAccess::ENDED, NotificationAudience::Company, role: Role::OWNER),
        ];
    }
}

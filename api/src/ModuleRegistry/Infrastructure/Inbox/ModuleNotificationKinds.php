<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Infrastructure\Inbox;

use App\Inbox\Application\DeclaresNotificationKinds;
use App\Inbox\Application\NotificationAudience;
use App\Inbox\Application\NotificationKind;
use App\ModuleRegistry\Application\ModuleInterests;

/** A module the company waited for has arrived: told to whoever may switch it on. */
final readonly class ModuleNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        return [new NotificationKind(ModuleInterests::ARRIVED, NotificationAudience::Company, permission: ModuleInterests::PERMISSION)];
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A context's kinds of notification, declared in its own `Infrastructure/Inbox/`: publishing a type nobody declared is
 * refused, so every kind a person can be told is one they can choose how to be told.
 */
#[AutoconfigureTag('app.notification.kinds')]
interface DeclaresNotificationKinds
{
    /** @return list<NotificationKind> */
    public function notificationKinds(): array;
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;

final class InMemoryNotifications implements Notifications
{
    /** @var list<Notification> */
    public array $published = [];

    public function publish(Notification $notification): void
    {
        $this->published[] = $notification;
    }
}

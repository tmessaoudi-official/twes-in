<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataHealth\Infrastructure\Inbox;

use App\DataHealth\Application\RunDataChecks;
use App\Inbox\Application\DeclaresNotificationKinds;
use App\Inbox\Application\NotificationAudience;
use App\Inbox\Application\NotificationKind;

/**
 * A check that found something tells the operators, by mail as well, since nobody watches the worker's logs at night.
 * Its mail is handed how many were found as %count%, so « Textes » checks every wording of it for its forms.
 */
final readonly class DataHealthNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        return [new NotificationKind(RunDataChecks::NOTIFICATION_FAILED, NotificationAudience::Platform, mailed: true, count: 'count')];
    }
}

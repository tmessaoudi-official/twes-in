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
use App\Tenancy\Application\Signup\CompleteSignup;

/** A company signed up and waiting for approval: told to the operators, who decide it, by the bell and by mail. */
final readonly class SignupNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        return [new NotificationKind(CompleteSignup::AWAITING_APPROVAL, NotificationAudience::Platform)];
    }
}

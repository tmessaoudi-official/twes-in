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
use App\Tenancy\Application\Closing\CloseDownCompany;
use App\Tenancy\Application\Closing\TellClosedCompaniesNearRemoval;
use App\Tenancy\Domain\Model\Role;

/**
 * An owner closed the company down, or took the closing back: told to everyone else working there, who would
 * otherwise find out from a refused save. Its owners are told again some days before it is removed for good.
 */
final readonly class ClosingNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        return [
            new NotificationKind(CloseDownCompany::CLOSED_DOWN, NotificationAudience::Company, days: ['retain_until'], moments: ['reopen_until']),
            new NotificationKind(CloseDownCompany::REOPENED, NotificationAudience::Company),
            new NotificationKind(TellClosedCompaniesNearRemoval::REMOVAL_NEAR, NotificationAudience::Company, role: Role::OWNER, days: ['retain_until']),
        ];
    }
}

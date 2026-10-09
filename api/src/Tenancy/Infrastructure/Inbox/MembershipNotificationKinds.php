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
use App\Tenancy\Application\Invitation\AcceptInvitation;
use App\Tenancy\Application\Invitation\InviteToCompany;

/**
 * Someone joined: told to every member of the company. An invitation received is about the account, since the
 * person is not yet a member of the company that invites them. It is not mailed again: the invitation's own mail is
 * the only way to accept it, and an invitee may have no account to choose with.
 */
final readonly class MembershipNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        return [
            new NotificationKind(AcceptInvitation::ACCEPTED, NotificationAudience::Company),
            new NotificationKind(InviteToCompany::RECEIVED, NotificationAudience::Personal, mailed: false),
        ];
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

/**
 * A mail's stop link, followed and confirmed: that kind's e-mail off for the person the link was written for, with no
 * session. A token this API did not sign, and a kind no longer told to them there, are refused alike.
 */
final readonly class StopNotificationMail
{
    public function __construct(private MailStopTokens $tokens, private NotificationPreferences $preferences)
    {
    }

    /** @throws NotificationKindNotOffered */
    public function handle(string $token): void
    {
        $stop = $this->tokens->read($token) ?? throw new NotificationKindNotOffered('The link was not signed here.');
        $this->preferences->stopMail($stop->userId, $stop->companyId, $stop->type);
    }
}

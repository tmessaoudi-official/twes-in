<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

/**
 * The token a mail's stop link carries, which stands in for a session: whoever holds it may turn off that one kind's
 * e-mail, and nothing else. It never expires, as an unsubscribe link must keep working in an old mail.
 */
interface MailStopTokens
{
    public function issue(MailStop $stop): string;

    /** null for a token this API did not sign, or one it cannot read */
    public function read(string $token): ?MailStop;
}

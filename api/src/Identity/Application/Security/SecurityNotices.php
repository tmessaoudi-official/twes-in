<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Security;

use Symfony\Component\Uid\Uuid;

/**
 * Tells an account's owner, by mail, of a change to how it signs in. Called inside the change's own transaction: the
 * notice leaves with the change or not at all, and the worker sends it, so a mail server that is down never refuses
 * the change. No one can turn it off, unlike a notification: whoever took a session must not silence it first.
 */
interface SecurityNotices
{
    /**
     * @param string|null             $passkeyName the passkey added or removed, as its owner named it
     * @param \DateTimeImmutable|null $until       when a lock ends
     */
    public function tell(Uuid $userId, SecurityChange $change, ?string $passkeyName = null, ?\DateTimeImmutable $until = null): void;
}

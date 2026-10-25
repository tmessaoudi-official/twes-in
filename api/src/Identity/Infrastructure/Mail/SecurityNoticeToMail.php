<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

/**
 * A security notice waiting for the worker (routed async in messenger.yaml). The account's id travels, not its address.
 * `until` is when a lock ends, as an ATOM moment; a notice queued before it existed arrives without one.
 */
final readonly class SecurityNoticeToMail
{
    public function __construct(public string $userId, public string $change, public ?string $passkeyName = null, public ?string $until = null)
    {
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Mail;

/** A notification's mail, waiting for the worker (routed async in messenger.yaml). Only ids and the payload travel. */
final readonly class NotificationToMail
{
    /** @param array<string, scalar|null> $payload */
    public function __construct(
        public string $userId,
        public ?string $companyId,
        public string $type,
        public array $payload,
    ) {
    }
}

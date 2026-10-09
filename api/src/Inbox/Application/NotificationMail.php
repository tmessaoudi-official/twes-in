<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

/** One notification as a mail, in its reader's language, with the token of the link that stops that kind's mail. */
final readonly class NotificationMail
{
    /** @param array<string, scalar|null> $payload */
    public function __construct(
        public string $to,
        public string $locale,
        public string $type,
        public ?string $companyName,
        public array $payload,
        public string $stopToken,
    ) {
    }
}

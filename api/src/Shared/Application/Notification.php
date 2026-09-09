<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Something a person should be told about as it happens. The channel names who may see it, and is never a
 * transport detail: "user:<uuid>" and "company:<uuid>" are the two shapes G1b produces.
 */
final readonly class Notification
{
    /** @param array<string, scalar|null> $payload */
    public function __construct(
        public string $channel,
        public string $type,
        public array $payload = [],
    ) {
    }
}

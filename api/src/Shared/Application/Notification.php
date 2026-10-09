<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Something a person should be told about as it happens. The channel names who may see it, and is never a
 * transport detail: "user:<uuid>" and "company:<uuid>" are the two shapes G1b produces. A notification to one person
 * about one of their companies names that company too, since each person chooses per company how a kind is told.
 * A notification to a company that tells of one member's act names that member, who is not told of what they did.
 */
final readonly class Notification
{
    /**
     * @param array<string, scalar|null> $payload
     * @param string|null                $companyId the company a "user:" notification is about, when it is about one
     * @param string|null                $actorId   the member whose act a "company:" notification tells of, left out of it
     */
    public function __construct(
        public string $channel,
        public string $type,
        public array $payload = [],
        public ?string $companyId = null,
        public ?string $actorId = null,
    ) {
    }
}

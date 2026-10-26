<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Session;

use App\Tenancy\Application\Closing\ClosedDown;

/** Where a user is working right now: the session company, their role in it, the permissions that role grants. */
final readonly class WorkingContext
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $companyId,
        public string $name,
        public string $countryCode,
        public string $currency,
        public string $locale,
        public string $timezone,
        public string $status,
        public string $role,
        public array $permissions,
        /** the id of the company's stored logo, which changes with it; null without one */
        public ?string $logoVersion = null,
        /** until when the owner lets support read the company, null while they do not */
        public ?\DateTimeImmutable $supportAccessUntil = null,
        /** until when a closed company is kept and may be reopened, null while it works */
        public ?ClosedDown $closing = null,
    ) {
    }
}

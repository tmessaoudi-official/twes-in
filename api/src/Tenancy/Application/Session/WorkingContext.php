<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Session;

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
    ) {
    }
}

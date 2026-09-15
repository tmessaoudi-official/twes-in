<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/** A company as its operators review it: who it is, where it stands, and who owns it. */
final readonly class PlatformCompanyView
{
    /** @param list<string> $owners the owners' addresses */
    public function __construct(
        public string $id,
        public string $name,
        public string $countryCode,
        public string $status,
        public string $createdAt,
        public array $owners,
    ) {
    }
}

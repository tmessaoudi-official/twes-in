<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/** What an operator supplies to open a company. Validation of the shape belongs to the adapter. */
final readonly class NewCompany
{
    public function __construct(
        public string $name,
        public string $countryCode,
        /** Null: its country's fiscal preset's. */
        public ?string $currency,
        /** Null: the first language its country's fiscal preset prints in. */
        public ?string $locale,
        /** Null: the zone its country's fiscal preset starts a company in. */
        public ?string $timezone,
    ) {
    }
}

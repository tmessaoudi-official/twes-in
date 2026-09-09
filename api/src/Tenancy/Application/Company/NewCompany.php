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
        public string $currency,
        public string $locale,
        public string $timezone,
    ) {
    }
}

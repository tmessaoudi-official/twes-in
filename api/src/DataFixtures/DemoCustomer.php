<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures;

/** One customer of a demo company, as `DemoCompanies` writes it. */
final readonly class DemoCustomer
{
    /** @param array<string, string> $identifiers keyed by the preset's identifier keys */
    public function __construct(
        public string $name,
        public string $city,
        /** Null for a customer in the company's own country. */
        public ?string $country = null,
        public bool $individual = false,
        /** A customer tax regime of the preset. */
        public string $regime = 'standard',
        /** Index into the company's customer groups; null for none. */
        public ?int $group = null,
        public bool $inactive = false,
        /** Whether its invoices carry the company's withholding tax. */
        public bool $withheld = false,
        public array $identifiers = [],
    ) {
    }
}

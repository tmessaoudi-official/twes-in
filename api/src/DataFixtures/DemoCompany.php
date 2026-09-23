<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures;

use App\Tenancy\Domain\CompanyProfile;

/**
 * One demo company as data: who it is and what it keeps. `DemoCompanies` writes it through the use cases; nothing
 * here is random, so every load yields the same rows, only dated relative to the day it runs.
 *
 * Tax codes and unit codes are the fiscal preset's (api/config/fiscal/<CC>.yaml).
 */
final readonly class DemoCompany
{
    /**
     * @param list<string>                                                                                                                                                                   $customerGroups
     * @param list<DemoCustomer>                                                                                                                                                             $customers
     * @param array<string, string|null>                                                                                                                                                     $productCategories name => parent name
     * @param list<array{ref: string, name: string, service?: true, unit: string, price: numeric-string, taxes: list<string>, category: string, inactive?: true, tracking?: 'lot'|'serial'}> $products
     * @param array<string, string|null>                                                                                                                                                     $expenseCategories name => parent name
     * @param list<array{name: string, city: string, category: string, amount: numeric-string, untaxed?: true}>                                                                              $vendors
     * @param list<array{string, string, string}>                                                                                                                                            $contacts          first name, last name, role: one each for the first key accounts
     */
    public function __construct(
        public string $name,
        public string $country,
        public string $currency,
        public string $timezone,
        /** Decimals of the currency: what a payment may carry. */
        public int $scale,
        public CompanyProfile $profile,
        /** The VAT an expense is charged. */
        public string $vatCode,
        /** A document tax a key account's invoices withhold, or null where the preset has none. */
        public ?string $withholdingCode,
        public array $customerGroups,
        public array $customers,
        public array $productCategories,
        public array $products,
        public array $expenseCategories,
        public array $vendors,
        public array $contacts,
    ) {
    }
}

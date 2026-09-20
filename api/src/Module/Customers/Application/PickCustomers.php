<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Application;

use App\Fiscal\Domain\TaxFamily;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerRepository;
use App\Tenancy\Domain\Company;

/**
 * The few customers a person means while typing in a form (docs/SPEC.md § 7, 2026-09-17, ruling 3), in the shape a
 * document header starts from: the tax families the customer's regime leaves out, its default discount and its
 * default taxes. A form used to be handed every active customer before it was filled in at all.
 */
final readonly class PickCustomers
{
    /** What a picker shows at once: enough to recognise the right one, few enough to read (§ 7, 2026-09-17). */
    public const int SHOWN = 20;

    public function __construct(private CustomerRepository $customers)
    {
    }

    /**
     * @return list<array{id: string, number: string, name: string, excludedFamilies: list<string>, defaultDiscountRate: string|null, defaultTaxComponentIds: list<string>}>
     */
    public function matching(Company $company, string $words, int $limit = self::SHOWN): array
    {
        return array_map(static fn (Customer $customer): array => [
            'id' => $customer->getId()->toRfc4122(),
            'number' => $customer->getNumber(),
            'name' => $customer->getProfile()->name,
            'excludedFamilies' => array_map(static fn (TaxFamily $family): string => $family->value, $customer->getTaxRegime()->getExcludedFamilies()),
            'defaultDiscountRate' => $customer->getProfile()->defaultDiscountRate,
            'defaultTaxComponentIds' => $customer->getDefaultTaxComponentIds(),
        ], $this->customers->pick($company->getId(), $words, max(1, min($limit, self::SHOWN))));
    }
}

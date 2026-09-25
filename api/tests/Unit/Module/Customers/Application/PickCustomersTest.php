<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Customers\Application;

use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Module\Customers\Application\PickCustomers;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use App\Tests\Support\InMemoryCustomers;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;

/**
 * A form offers a document's taxes from the row it picks a customer with, so the row's `excludedFamilies` must be what
 * the API will refuse: the customer's regime's and the company's own (audit EXT-03).
 */
final class PickCustomersTest extends TestCase
{
    public function testARowLeavesOutWhatTheCustomersRegimeAndTheCompanysOwnDoNotCharge(): void
    {
        $company = new Company('Atelier', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $customers = new InMemoryCustomers();
        $now = new \DateTimeImmutable('2026-09-25');
        $customer = Customer::create($company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Maison Durand'), null, new CustomerTaxRegime('FR', 'standard', 'fiscal.regime.standard', [], null, 0, $now), [], $now);
        $customers->save($customer);
        $pick = new PickCustomers($customers, new ExcludedTaxFamilies(ShippedFiscalPresets::presets()));

        self::assertSame([], $pick->byIds($company, [$customer->getId()])[0]['excludedFamilies']);

        $company->reviseProfile(new CompanyProfile(vatRegime: 'franchise'));
        self::assertSame(['vat'], $pick->byIds($company, [$customer->getId()])[0]['excludedFamilies']);
        self::assertSame(['vat'], $pick->matching($company, 'Durand')[0]['excludedFamilies']);
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Invoices;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Invoices\Domain\CustomerCreditEntry;
use App\Module\Invoices\Domain\CustomerCreditRepository;
use App\Module\Invoices\Domain\PaymentDetails;
use App\Shared\Application\Transactions;
use App\Shared\Domain\PaymentMethod;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A customer's credit balance is the guard against spending more credit than there is, so it is read as the database
 * sums it, digit for digit (docs/SPEC.md § 7, audit E-1): through a float, a balance of eighteen digits came back
 * rounded to what a double holds.
 */
final class ExactCreditBalanceTest extends KernelTestCase
{
    public function testABalancePastAFloatsPrecisionIsReadExactly(): void
    {
        self::bootKernel();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $em->persist($company);
        $em->flush();
        static::getContainer()->get(ProvisionCompany::class)->handle($company);
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($company, 'CLI-1', new CustomerProfile(CustomerKind::Company, 'Grossiste'), null, $regime, [], new \DateTimeImmutable());
        $em->persist($customer);
        $em->flush();
        $credits = static::getContainer()->get(CustomerCreditRepository::class);
        $credits->save(CustomerCreditEntry::deposit($customer, new PaymentDetails(new \DateTimeImmutable('2026-10-01'), '99999999999.999', PaymentMethod::Transfer), null, new \DateTimeImmutable()));
        // A thousand more of the largest deposit a row holds: a sum of eighteen digits, past what a double carries.
        $em->getConnection()->executeStatement(
            'INSERT INTO customer_credit_entry (id, entry_date, kind, amount, reference, notes, invoice_id, payment_id, recorded_by, created_at, company_id, customer_id)
             SELECT gen_random_uuid(), entry_date, kind, amount, reference, notes, invoice_id, payment_id, recorded_by, created_at, company_id, customer_id
             FROM customer_credit_entry, generate_series(1, 1000) WHERE customer_id = ?',
            [$customer->getId()->toRfc4122()],
        );

        self::assertSame('100099999999998.999', $credits->balance($company->getId(), $customer->getId()), 'through a double it read 100099999999999.000');
        self::assertSame('100099999999998.999', static::getContainer()->get(Transactions::class)->run(static fn (): string => $credits->lockedBalance($company->getId(), $customer->getId())));
    }
}

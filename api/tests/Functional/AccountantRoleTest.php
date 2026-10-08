<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Module\Customers\Domain\Customer;
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * The built-in accountant, « Comptable » (docs/SPEC.md § 7, 2026-09-20 01:33, row 92): invited to the company, they see
 * the documents and take the accountant's files, and nothing else. They change nothing: no invoice, expense or customer
 * is written, no product, stock, member or setting is read.
 */
final class AccountantRoleTest extends ApiTestCase
{
    private Company $company;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, 'CLI-0001', self::aTunisianBusiness('Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
        $this->createUser('accountant@twes.local', 'password-1234', $this->company, SeedPlatform::BUILT_IN_ROLES[Role::ACCOUNTANT], Role::ACCOUNTANT);
        $this->login('accountant@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    public function testAnAccountantReadsTheDocumentsAndTakesTheFiles(): void
    {
        foreach (['/invoices', '/delivery-notes', '/quotes', '/expenses', '/customers/'.$this->customerId, '/vendors'] as $read) {
            $this->client->request('GET', $this->path().$read);
            self::assertResponseIsSuccessful($read);
        }

        $this->stepUp('password-1234');
        foreach (['sales-journal', 'purchases-journal', 'payments-journal', 'vat-summary'] as $file) {
            $this->client->request('GET', $this->path().'/exports/'.$file.'.csv?from=2026-09-01&to=2026-09-30');
            self::assertResponseIsSuccessful($file);
        }
    }

    public function testAnAccountantChangesNothing(): void
    {
        $this->postJson($this->path().'/invoices', ['customerId' => $this->customerId, 'lines' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'no invoice');
        $this->postJson($this->path().'/expense-categories', ['name' => 'Carburant', 'parentId' => null, 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'no expense category');
        $this->postJson($this->path().'/customers', ['number' => 'CLI-0002', 'kind' => 'individual', 'name' => 'Sonia Ben Ali', 'taxRegime' => 'standard', 'defaultTaxComponentIds' => [], 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'no customer');
        $this->sendJson('PUT', $this->path().'/closing', ['closedThrough' => '2026-01-31']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'nor closes the books, which is the company\'s');
    }

    public function testAnAccountantSeesNeitherTheCatalogueNorTheMembers(): void
    {
        foreach (['/products', '/stock-levels', '/members', '/roles'] as $hidden) {
            $this->client->request('GET', $this->path().$hidden);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $hidden);
        }
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}

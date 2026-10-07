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
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * The question before issuing names the number the draft will carry (docs/SPEC.md § 7, 2026-09-26 23:04: a precise
 * preview before what cannot be undone), and asking takes nothing from the series.
 */
final class InvoiceNextNumberTest extends ApiTestCase
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
        $customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['invoice.read', 'invoice.write', 'invoice.issue'], 'member');
        $this->login('sales@twes.local', 'password-1234');
    }

    public function testADraftIsToldTheNumberItWillCarryAndAskingTakesNone(): void
    {
        $first = $this->draft();
        $second = $this->draft();

        $this->getJson($this->path($first).'/next-number');
        self::assertResponseIsSuccessful();
        $told = $this->stringAt($this->json(), 'number');
        $this->getJson($this->path($second).'/next-number');
        self::assertSame($told, $this->stringAt($this->json(), 'number'), 'asking took nothing from the series');

        $this->postJson($this->path($first).'/issue', null);
        self::assertResponseIsSuccessful();
        self::assertSame($told, $this->stringAt($this->json(), 'number'), 'the number said is the number given');

        $this->getJson($this->path($second).'/next-number');
        self::assertNotSame($told, $this->stringAt($this->json(), 'number'));
        $next = $this->stringAt($this->json(), 'number');
        $this->postJson($this->path($second).'/issue', null);
        self::assertSame($next, $this->stringAt($this->json(), 'number'));
    }

    public function testAnIssuedInvoiceHasNoNumberToBeToldAndAnotherCompanysNone(): void
    {
        $id = $this->draft();
        $this->postJson($this->path($id).'/issue', null);

        $this->getJson($this->path($id).'/next-number');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $this->getJson('/api/companies/'.$this->createCompany('Elsewhere')->getId()->toRfc4122().'/invoices/'.$id.'/next-number');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function draft(): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $this->postJson($this->path(), [
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => null,
            'lines' => [['description' => 'Conseil', 'quantity' => '1', 'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => '100', 'taxComponentIds' => []]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function path(?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/invoices'.(null === $id ? '' : '/'.$id);
    }
}

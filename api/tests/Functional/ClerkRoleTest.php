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
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * The built-in clerk, « Caissier / Vendeur » (docs/SPEC.md § 7, audit 2026-10-06 B-12): they sell, which is issuing an
 * invoice and taking its payment, and draft delivery notes; a credit note, which reverses revenue, a validated delivery
 * note, a customer's record and a product's cost stay a manager's.
 */
final class ClerkRoleTest extends ApiTestCase
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
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, SeedPlatform::BUILT_IN_ROLES[Role::CLERK], Role::CLERK);
        $this->createUser('manager@twes.local', 'password-1234', $this->company, SeedPlatform::BUILT_IN_ROLES[Role::ADMIN], 'manager');
    }

    public function testAClerkIssuesAnInvoiceAndTakesItsPayment(): void
    {
        $this->login('clerk@twes.local', 'password-1234');

        $id = $this->issued();
        // The company's day, not the server's: the two differ for an hour or two each night.
        $issueDay = $this->stringAt($this->json(), 'issueDate');

        $this->postJson($this->path($id).'/payments', ['date' => $issueDay, 'amount' => '10', 'method' => 'cash', 'reference' => null, 'notes' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testAClerkCannotCreateOrIssueACreditNoteButAManagerCan(): void
    {
        $this->login('manager@twes.local', 'password-1234');
        $invoice = $this->issued();
        $this->postJson($this->path($invoice).'/credit-notes', ['creditNoteReason' => 'Retour']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'a manager creates one');
        $credit = $this->stringAt($this->json(), 'id');

        $this->client->getCookieJar()->clear();
        $this->login('clerk@twes.local', 'password-1234');

        $this->postJson($this->path($invoice).'/credit-notes', ['creditNoteReason' => 'Retour']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a clerk creates none');
        $this->postJson($this->path($credit).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'nor issues the manager\'s draft');
        // Audit 2026-10-06, C-F4: nor changes it, nor cancels it.
        $this->sendJson('PUT', $this->path($credit), [
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => [],
            'lines' => [['description' => 'Prestation', 'quantity' => '1', 'unitId' => $this->unitId(), 'unitPriceNet' => '1', 'taxComponentIds' => []]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'nor changes the manager\'s draft');
        $this->postJson($this->path($credit).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'nor cancels it');

        $this->client->getCookieJar()->clear();
        $this->login('manager@twes.local', 'password-1234');
        $this->postJson($this->path($credit).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, 'a manager does');
    }

    public function testAClerkReadsCustomersButWritesNoneAndSeesNoMembers(): void
    {
        $this->login('clerk@twes.local', 'password-1234');
        $company = '/api/companies/'.$this->company->getId()->toRfc4122();

        $this->client->request('GET', $company.'/customers/'.$this->customerId);
        self::assertResponseIsSuccessful();
        $this->postJson($company.'/customers', ['number' => 'CLI-0002', 'kind' => 'individual', 'name' => 'Sonia Ben Ali', 'taxRegime' => 'standard', 'defaultTaxComponentIds' => [], 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a customer\'s record is a manager\'s');
        $this->client->request('GET', $company.'/members');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'nor who the members are');
    }

    private function path(string $id): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/invoices/'.$id;
    }

    private function issued(): string
    {
        $this->postJson('/api/companies/'.$this->company->getId()->toRfc4122().'/invoices', [
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => [],
            'lines' => [['description' => 'Prestation', 'quantity' => '1', 'unitId' => $this->unitId(), 'unitPriceNet' => '100', 'taxComponentIds' => []]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $id;
    }

    private function unitId(): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);

        return $unit->getId()->toRfc4122();
    }
}

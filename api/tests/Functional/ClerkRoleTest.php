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
 * The built-in member is the shop clerk (docs/SPEC.md § 7): they sell, which is issuing an invoice and taking its payment,
 * but a credit note, which reverses revenue, stays a manager's.
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
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, SeedPlatform::BUILT_IN_ROLES[Role::MEMBER], 'clerk');
        $this->createUser('manager@twes.local', 'password-1234', $this->company, SeedPlatform::BUILT_IN_ROLES[Role::ADMIN], 'manager');
    }

    public function testAClerkIssuesAnInvoiceAndTakesItsPayment(): void
    {
        $this->login('clerk@twes.local', 'password-1234');

        $id = $this->issued();

        $this->postJson($this->path($id).'/payments', ['date' => (new \DateTimeImmutable())->format('Y-m-d'), 'amount' => '10', 'method' => 'cash', 'reference' => null, 'notes' => null]);
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

        $this->client->getCookieJar()->clear();
        $this->login('manager@twes.local', 'password-1234');
        $this->postJson($this->path($credit).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, 'a manager does');
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

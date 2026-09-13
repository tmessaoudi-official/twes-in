<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

final class ContactsTest extends ApiTestCase
{
    private Company $company;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Acme');
        $this->customer = $this->customerOf($this->company, 'CLI-0001');
    }

    public function testTheFirstContactIsPrimaryAndAnotherCanTakeItOver(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->postJson($this->path(), ['firstName' => 'Leila', 'lastName' => 'Ben Salah', 'email' => 'leila@carthage.tn', 'role' => 'Comptable', 'isPrimary' => false]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertTrue($this->json()['isPrimary']);

        $this->postJson($this->path(), ['firstName' => 'Karim', 'isPrimary' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->getJson($this->path());
        $rows = $this->jsonList();
        self::assertSame(['Karim', 'Leila'], array_column($rows, 'firstName'));
        self::assertSame([true, false], array_column($rows, 'isPrimary'));
    }

    public function testAContactIsRevisedAndRemoved(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), ['firstName' => 'Leila', 'isPrimary' => true]);
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), ['firstName' => 'Leila', 'lastName' => 'Trabelsi', 'phone' => '+216 71 000 000', 'isPrimary' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame('Trabelsi', $this->json()['lastName']);

        $this->sendJson('DELETE', $this->path($id));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path());
        self::assertSame([], $this->jsonList());
        self::assertEquals(3, $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'contact'"));
    }

    public function testAContactWithoutANameIsRefused(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->postJson($this->path(), ['email' => 'nobody@carthage.tn', 'isPrimary' => false]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('lastName', (string) $this->client->getResponse()->getContent());
    }

    public function testAContactIsOnlyReachedThroughItsCustomerAndAReaderOnlyReads(): void
    {
        $other = $this->customerOf($this->company, 'CLI-0002');
        $theirs = $this->customerOf($this->createCompany('Globex'), 'CLI-0001');
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), ['firstName' => 'Leila', 'isPrimary' => true]);
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id, $other), ['firstName' => 'Hijacked', 'isPrimary' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path(null, $theirs));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAReaderCannotAddAContact(): void
    {
        $this->signedIn(['customer.read']);

        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        $this->postJson($this->path(), ['firstName' => 'Leila', 'isPrimary' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function customerOf(Company $company, string $number): Customer
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($company, $number, new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(?string $contactId = null, ?Customer $customer = null): string
    {
        $customer ??= $this->customer;

        return '/api/companies/'.$customer->getCompany()->getId()->toRfc4122().'/customers/'.$customer->getId()->toRfc4122().'/contacts'.(null === $contactId ? '' : '/'.$contactId);
    }
}

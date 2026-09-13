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
use App\Module\Customers\Domain\CustomerGroup;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * The parties chain at the customer-group and customer levels, through the one settings endpoint: a group's value
 * reaches its customers, a customer's own wins, and both belong to whoever may change customers.
 */
final class CustomerSettingsTest extends ApiTestCase
{
    private const string TERMS = 'document.payment_terms_days';

    private Company $company;
    private CustomerGroup $group;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->group = CustomerGroup::create($this->company, 'Grossistes', null, new \DateTimeImmutable());
        $this->em()->persist($this->group);
        $this->customer = $this->customerOf($this->company, $this->group);
        $this->em()->flush();
    }

    public function testAGroupsTermsReachItsCustomerUntilTheCustomerSaysOtherwise(): void
    {
        $this->signedIn(['company.read', 'customer.read', 'customer.write']);

        $this->sendJson('PUT', $this->path(self::TERMS), ['level' => 'customer_group', 'customerGroupId' => $this->groupId(), 'value' => 45]);
        self::assertResponseIsSuccessful();
        self::assertSame('customer_group', $this->json()['source']);

        $this->getJson($this->path().'?chain=parties&customerId='.$this->customerId());
        self::assertResponseIsSuccessful();
        $terms = $this->row(self::TERMS);
        self::assertSame([45, 'customer_group'], [$terms['value'], $terms['source']]);
        self::assertSame(['customer'], $terms['writableLevels']);

        $this->getJson($this->path().'?chain=parties&customerGroupId='.$this->groupId());
        self::assertSame(['customer_group'], $this->row(self::TERMS)['writableLevels']);

        $this->sendJson('PUT', $this->path(self::TERMS), ['level' => 'customer', 'customerId' => $this->customerId(), 'value' => 10]);
        self::assertResponseIsSuccessful();
        self::assertSame([10, 'customer'], [$this->json()['value'], $this->json()['source']]);

        $this->sendJson('DELETE', $this->path(self::TERMS).'?level=customer&customerId='.$this->customerId());
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path().'?chain=parties&customerId='.$this->customerId());
        self::assertSame(45, $this->row(self::TERMS)['value']);

        $this->getJson($this->path().'?chain=parties');
        self::assertSame(30, $this->row(self::TERMS)['value'], 'the company itself still says 30');
    }

    public function testAReaderOfCustomersReadsTheirSettingsButChangesNone(): void
    {
        $this->signedIn(['company.read', 'customer.read']);

        $this->getJson($this->path().'?chain=parties&customerId='.$this->customerId());
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->row(self::TERMS)['writableLevels']);

        $this->sendJson('PUT', $this->path(self::TERMS), ['level' => 'customer', 'customerId' => $this->customerId(), 'value' => 10]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('DELETE', $this->path(self::TERMS).'?level=customer_group&customerGroupId='.$this->groupId());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testWithoutCustomerReadACustomersSettingsAreNotFound(): void
    {
        $this->signedIn(['company.read']);

        $this->getJson($this->path().'?chain=parties&customerId='.$this->customerId());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherCompanysCustomerOrGroupIsNotFound(): void
    {
        $globex = $this->createCompany('Globex');
        $theirGroup = CustomerGroup::create($globex, 'Export', null, new \DateTimeImmutable());
        $this->em()->persist($theirGroup);
        $theirs = $this->customerOf($globex, null);
        $this->em()->flush();
        $this->signedIn(['company.read', 'customer.read', 'customer.write']);

        $this->getJson($this->path().'?chain=parties&customerId='.$theirs->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('PUT', $this->path(self::TERMS), ['level' => 'customer_group', 'customerGroupId' => $theirGroup->getId()->toRfc4122(), 'value' => 45]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path().'?chain=parties&customerGroupId=not-a-uuid');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheSubjectIsNamedOnceAndMatchesTheLevel(): void
    {
        $this->signedIn(['company.read', 'customer.read', 'customer.write']);

        $this->getJson($this->path().'?chain=parties&customerId='.$this->customerId().'&customerGroupId='.$this->groupId());
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->sendJson('PUT', $this->path(self::TERMS), ['level' => 'customer', 'value' => 10]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('customerId', (string) $this->client->getResponse()->getContent());
    }

    public function testDeletingAGroupForgetsItsSettings(): void
    {
        $empty = CustomerGroup::create($this->company, 'Détaillants', null, new \DateTimeImmutable());
        $this->em()->persist($empty);
        $this->em()->flush();
        $emptyId = $empty->getId()->toRfc4122();
        $this->signedIn(['company.read', 'customer.read', 'customer.write']);
        $this->sendJson('PUT', $this->path(self::TERMS), ['level' => 'customer_group', 'customerGroupId' => $emptyId, 'value' => 45]);
        self::assertResponseIsSuccessful();

        $this->sendJson('DELETE', '/api/companies/'.$this->company->getId()->toRfc4122().'/customer-groups/'.$emptyId);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertEquals(0, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM setting WHERE level = 'customer_group'"));
    }

    private function customerOf(Company $company, ?CustomerGroup $group): Customer
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($company, 'CLI-0001', new CustomerProfile(CustomerKind::Individual, 'Amel'), $group, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);

        return $customer;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function row(string $key): array
    {
        foreach ($this->jsonList() as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        self::fail("No setting $key in the response.");
    }

    private function groupId(): string
    {
        return $this->group->getId()->toRfc4122();
    }

    private function customerId(): string
    {
        return $this->customer->getId()->toRfc4122();
    }

    private function path(?string $key = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/settings'.(null === $key ? '' : '/'.$key);
    }
}

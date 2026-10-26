<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\Model\CustomerGroup;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

final class CustomerGroupsTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
    }

    public function testAWriterCreatesRevisesAndDeletesAGroup(): void
    {
        $this->signedIn(['customer.read', 'customer.write', 'customer.remove']);

        $this->postJson($this->path(), ['name' => 'Grossistes', 'description' => 'Remise de volume']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json();
        self::assertSame('Grossistes', $created['name']);
        self::assertSame(0, $created['customerCount']);
        $id = $this->stringAt($created, 'id');

        self::assertSame(1, $created['version']);
        $this->sendJson('PUT', $this->path($id), ['name' => 'Grossistes B2B', 'description' => null, 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['description']);
        self::assertSame(2, $this->json()['version']);

        $this->getJson($this->path());
        self::assertSame(['Grossistes B2B'], array_column($this->jsonList(), 'name'));

        $this->sendJson('DELETE', $this->path($id));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path());
        self::assertSame([], $this->jsonList());

        $actions = $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_type = 'customer_group' ORDER BY at, action");
        self::assertEqualsCanonicalizing(['customer_group.created', 'customer_group.revised', 'customer_group.deleted'], $actions);
    }

    public function testARevisionOfAVersionSavedSinceIsRefusedAsChangedAndWhatWasSavedStays(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), ['name' => 'Grossistes', 'description' => null]);
        $id = $this->stringAt($this->json(), 'id');
        $this->sendJson('PUT', $this->path($id), ['name' => 'Grossistes B2B', 'description' => null, 'version' => 1]);
        self::assertSame(2, $this->json()['version']);

        $this->sendJson('PUT', $this->path($id), ['name' => 'Revendeurs', 'description' => null, 'version' => 1]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('/problems/record-changed', $this->json()['type']);
        $this->getJson($this->path());
        self::assertSame([['Grossistes B2B', 2]], array_map(static fn (array $row): array => [$row['name'], $row['version']], $this->jsonList()));
    }

    public function testARevisionThatChangesNothingKeepsItsVersionAndOneWithoutAVersionIsRefused(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), ['name' => 'Grossistes', 'description' => null]);
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), ['name' => 'Grossistes', 'description' => null, 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['version']);

        $this->sendJson('PUT', $this->path($id), ['name' => 'Revendeurs', 'description' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['version'], array_column($this->arrayAt($this->json(), 'violations'), 'propertyPath'));
    }

    public function testANameAnotherGroupHasAnswersConflictAndABlankOneIsRefused(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), ['name' => 'Grossistes']);

        $this->postJson($this->path(), ['name' => 'Grossistes']);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $this->postJson($this->path(), ['name' => '  ']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'name', 'reason' => 'value_required', 'params' => []]], $this->refusals());

        $this->postJson($this->path(), ['name' => 'Détaillants', 'description' => str_repeat('x', 501)]);
        self::assertSame([['propertyPath' => 'description', 'reason' => 'invalid_text', 'params' => ['max' => 500]]], $this->refusals());
    }

    public function testAGroupHoldingACustomerIsKept(): void
    {
        $group = CustomerGroup::create($this->company, 'Grossistes', null, new \DateTimeImmutable());
        $this->em()->persist($group);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $this->em()->persist(Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Individual, 'Amel'), $group, $regime, [], new \DateTimeImmutable()));
        $this->em()->flush();
        $this->signedIn(['customer.read', 'customer.write', 'customer.remove']);

        $this->getJson($this->path());
        self::assertSame(1, $this->jsonList()[0]['customerCount']);

        $this->sendJson('DELETE', $this->path($group->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testAReaderOnlyReadsAndAnotherCompanysGroupIsNotFound(): void
    {
        $theirs = CustomerGroup::create($this->createCompany('Globex'), 'Export', null, new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $mine = CustomerGroup::create($this->company, 'Détaillants', null, new \DateTimeImmutable());
        $this->em()->persist($mine);
        $this->em()->flush();
        $this->signedIn(['customer.read']);

        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        $this->postJson($this->path(), ['name' => 'Grossistes']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->sendJson('DELETE', $this->path($mine->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path());
        self::assertSame(['Détaillants'], array_column($this->jsonList(), 'name'));

        $this->sendJson('DELETE', $this->path($theirs->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('PUT', $this->path('not-a-uuid'), ['name' => 'x', 'version' => 1]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testNobodySignedInReadsNothing(): void
    {
        $this->getJson($this->path());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(?string $groupId = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/customer-groups'.(null === $groupId ? '' : '/'.$groupId);
    }
}

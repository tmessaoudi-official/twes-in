<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Customers\Application;

use App\Fiscal\Domain\CustomerTaxRegime;
use App\Module\Customers\Application\CustomerGroupInUse;
use App\Module\Customers\Application\CustomerGroupNameTaken;
use App\Module\Customers\Application\CustomerGroupNotFound;
use App\Module\Customers\Application\ManageCustomerGroups;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCustomerGroups;
use App\Tests\Support\InMemoryCustomers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ManageCustomerGroupsTest extends TestCase
{
    private InMemoryCustomerGroups $groups;
    private InMemoryCustomers $customers;
    private InMemoryAuditTrail $audit;
    private ManageCustomerGroups $manage;
    private Company $company;

    protected function setUp(): void
    {
        $this->groups = new InMemoryCustomerGroups();
        $this->customers = new InMemoryCustomers();
        $this->audit = new InMemoryAuditTrail();
        $this->manage = new ManageCustomerGroups($this->groups, $this->customers, $this->audit, new MockClock('2026-09-14 09:00:00'));
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }

    public function testAGroupIsCreatedAndAuditedWithoutItsValues(): void
    {
        $actor = Uuid::v7();
        $group = $this->manage->create($this->company, 'Grossistes', 'Remise de volume', $actor);

        self::assertSame([$group], $this->manage->list($this->company));
        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame([ManageCustomerGroups::ENTITY_TYPE, ManageCustomerGroups::CREATED, []], [$entry->entityType, $entry->action, $entry->changes]);
        self::assertSame($actor, $entry->actorUserId);
        self::assertTrue($this->company->getId()->equals($entry->companyId));
    }

    public function testANameAnotherGroupOfTheCompanyHasIsRefused(): void
    {
        $this->manage->create($this->company, 'Grossistes', null, null);
        $retail = $this->manage->create($this->company, 'Détaillants', null, null);
        $this->manage->create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'Export', null, null);

        $this->manage->revise($this->company, $retail->getId(), 'Détaillants', 'Magasins', null);
        $this->manage->create($this->company, 'Export', null, null);
        try {
            $this->manage->revise($this->company, $retail->getId(), ' Grossistes ', null, null);
            self::fail('A group was renamed to a name another group has.');
        } catch (CustomerGroupNameTaken) {
        }
        $this->expectException(CustomerGroupNameTaken::class);
        $this->manage->create($this->company, 'Grossistes', null, null);
    }

    public function testARevisionIsAuditedWithTheFieldsItChangedAndNotAtAllWhenNothingChanged(): void
    {
        $group = $this->manage->create($this->company, 'Grossistes', null, null);

        $this->manage->revise($this->company, $group->getId(), 'Grossistes', null, null);
        self::assertCount(1, $this->audit->entries);

        $this->manage->revise($this->company, $group->getId(), 'Grossistes', 'Remise de volume', null);
        self::assertSame([ManageCustomerGroups::REVISED, ['fields' => ['description']]], [$this->audit->entries[1]->action, $this->audit->entries[1]->changes]);
    }

    public function testAGroupStillHoldingACustomerIsKept(): void
    {
        $group = $this->manage->create($this->company, 'Grossistes', null, null);
        $regime = new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, new \DateTimeImmutable());
        $customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Individual, 'Amel'), $group, $regime, [], new \DateTimeImmutable());
        $this->customers->save($customer);

        try {
            $this->manage->delete($this->company, $group->getId(), null);
            self::fail('A group holding a customer was deleted.');
        } catch (CustomerGroupInUse) {
        }
        self::assertCount(1, $this->manage->list($this->company));

        $customer->revise('CLI-0001', $customer->getProfile(), null, $regime, [], true, new \DateTimeImmutable());
        $this->manage->delete($this->company, $group->getId(), null);

        self::assertSame([], $this->manage->list($this->company));
        self::assertSame(ManageCustomerGroups::DELETED, $this->audit->entries[1]->action);
    }

    public function testAnotherCompanysGroupIsNotFound(): void
    {
        $theirs = $this->manage->create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'Grossistes', null, null);

        try {
            $this->manage->revise($this->company, $theirs->getId(), 'Mine now', null, null);
            self::fail("Another company's group was revised.");
        } catch (CustomerGroupNotFound) {
        }
        $this->expectException(CustomerGroupNotFound::class);
        $this->manage->delete($this->company, $theirs->getId(), null);
    }
}

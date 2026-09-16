<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Customers\Application;

use App\Fiscal\Domain\CustomerTaxRegime;
use App\Module\Customers\Application\ContactNotFound;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Application\ManageContacts;
use App\Module\Customers\Domain\ContactDetails;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryContacts;
use App\Tests\Support\InMemoryCustomers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ManageContactsTest extends TestCase
{
    private InMemoryCustomers $customers;
    private InMemoryAuditTrail $audit;
    private ManageContacts $manage;
    private Company $company;
    private Customer $customer;

    protected function setUp(): void
    {
        $this->customers = new InMemoryCustomers();
        $transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($transactions);
        $this->manage = new ManageContacts(new InMemoryContacts(), $this->customers, $this->audit, new MockClock('2026-09-14 09:00:00'), $transactions);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->customer = $this->customerOf($this->company, 'CLI-0001');
    }

    public function testTheFirstContactOfACustomerIsItsPrimaryOne(): void
    {
        $leila = $this->manage->add($this->company, $this->customer->getId(), self::details('Leila'), false, null);

        self::assertTrue($leila->isPrimary());
        self::assertSame([ManageContacts::ENTITY_TYPE, ManageContacts::CREATED, []], [$this->audit->entries[0]->entityType, $this->audit->entries[0]->action, $this->audit->entries[0]->changes]);
    }

    public function testMakingAnotherContactPrimaryHandsItOver(): void
    {
        $leila = $this->manage->add($this->company, $this->customer->getId(), self::details('Leila'), true, null);
        $karim = $this->manage->add($this->company, $this->customer->getId(), self::details('Karim'), false, null);
        self::assertFalse($karim->isPrimary());

        $this->manage->revise($this->company, $this->customer->getId(), $karim->getId(), self::details('Karim'), true, null);

        self::assertFalse($leila->isPrimary());
        self::assertSame([$karim, $leila], $this->manage->list($this->company, $this->customer->getId()));
    }

    public function testRemovingThePrimaryContactMakesTheNextOnePrimary(): void
    {
        $leila = $this->manage->add($this->company, $this->customer->getId(), self::details('Leila'), true, null);
        $karim = $this->manage->add($this->company, $this->customer->getId(), self::details('Karim'), false, null);

        $this->manage->remove($this->company, $this->customer->getId(), $leila->getId(), null);

        self::assertSame([$karim], $this->manage->list($this->company, $this->customer->getId()));
        self::assertTrue($karim->isPrimary());
        self::assertSame(ManageContacts::DELETED, $this->audit->entries[2]->action);
    }

    public function testARevisionThatChangesNothingIsNotAudited(): void
    {
        $leila = $this->manage->add($this->company, $this->customer->getId(), self::details('Leila'), true, null);

        $this->manage->revise($this->company, $this->customer->getId(), $leila->getId(), self::details(' Leila '), true, null);
        self::assertCount(1, $this->audit->entries);

        $this->manage->revise($this->company, $this->customer->getId(), $leila->getId(), self::details('Leïla'), true, null);
        self::assertSame(ManageContacts::REVISED, $this->audit->entries[1]->action);
    }

    public function testAContactIsReachedOnlyThroughItsOwnCustomerOfTheCompany(): void
    {
        $leila = $this->manage->add($this->company, $this->customer->getId(), self::details('Leila'), true, null);
        $other = $this->customerOf($this->company, 'CLI-0002');
        $theirs = $this->customerOf(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'CLI-0001');

        try {
            $this->manage->revise($this->company, $other->getId(), $leila->getId(), self::details('Leila'), true, null);
            self::fail('A contact was revised through another customer.');
        } catch (ContactNotFound) {
        }
        $this->expectException(CustomerNotFound::class);
        $this->manage->list($this->company, $theirs->getId());
    }

    private function customerOf(Company $company, string $number): Customer
    {
        $now = new \DateTimeImmutable();
        $customer = Customer::create($company, $number, new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $now), [], $now);
        $this->customers->save($customer);

        return $customer;
    }

    private static function details(string $firstName): ContactDetails
    {
        return new ContactDetails($firstName, 'Ben Salah', null, null, null);
    }
}

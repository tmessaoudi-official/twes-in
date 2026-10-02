<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\PriceLists\Application;

use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\Unit;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerGroup;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\PriceLists\Application\ManagePriceLists;
use App\Module\PriceLists\Application\PriceListInput;
use App\Module\PriceLists\Application\PriceListItemInput;
use App\Module\PriceLists\Application\PriceListNameTaken;
use App\Module\PriceLists\Application\PriceListNotFound;
use App\Module\PriceLists\Domain\InvalidPriceList;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCustomerGroups;
use App\Tests\Support\InMemoryCustomers;
use App\Tests\Support\InMemoryPriceLists;
use App\Tests\Support\InMemoryProducts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ManagePriceListsTest extends TestCase
{
    private InMemoryPriceLists $lists;
    private InMemoryProducts $products;
    private InMemoryCustomers $customers;
    private InMemoryCustomerGroups $groups;
    private InMemoryAuditTrail $audit;
    private ManagePriceLists $manage;
    private Company $company;
    private Product $screw;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-02 09:00:00');
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->lists = new InMemoryPriceLists();
        $this->products = new InMemoryProducts();
        $this->customers = new InMemoryCustomers();
        $this->groups = new InMemoryCustomerGroups();
        $transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($transactions);
        $this->manage = new ManagePriceLists($this->lists, $this->products, $this->customers, $this->groups, $this->audit, new MockClock('2026-10-02 09:00:00'), $transactions);
        $this->screw = $this->productIn($this->company, 'ART-001');
    }

    public function testAListIsCreatedWithItsPricesAndAuditedWithoutThem(): void
    {
        $actor = Uuid::v7();

        $list = $this->manage->create($this->company, $this->input('Public', items: [new PriceListItemInput($this->screw->getId(), '1', '12.5'), new PriceListItemInput($this->screw->getId(), '10', '11')]), $actor);

        self::assertSame([$list], $this->manage->list($this->company));
        self::assertSame([['1.000', '12.5000'], ['10.000', '11.0000']], array_map(static fn ($i) => [$i->getMinQuantity(), $i->getUnitPriceNet()], $list->getItems()));
        $entry = $this->audit->entries[0];
        self::assertSame([ManagePriceLists::ENTITY_TYPE, ManagePriceLists::CREATED, []], [$entry->entityType, $entry->action, $entry->changes]);
        self::assertSame($actor, $entry->actorUserId);
        self::assertTrue($list->getId()->equals($entry->entityId));
    }

    public function testANameTheCompanyAlreadyUsesIsRefusedButAnotherCompanysIsNot(): void
    {
        $this->manage->create($this->company, $this->input('Public'), null);
        $this->manage->create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), $this->input('Public'), null);

        $this->expectException(PriceListNameTaken::class);
        $this->manage->create($this->company, $this->input(' Public '), null);
    }

    public function testRenamingToAnotherListsNameIsRefusedButKeepingYourOwnIsNot(): void
    {
        $public = $this->manage->create($this->company, $this->input('Public'), null);
        $this->manage->create($this->company, $this->input('Gros'), null);

        $this->manage->revise($this->company, $public->getId(), $this->input('Public', active: false), null);
        $this->expectException(PriceListNameTaken::class);
        $this->manage->revise($this->company, $public->getId(), $this->input('Gros'), null);
    }

    public function testACustomerOrGroupOfAnotherCompanyIsRefusedNamingTheField(): void
    {
        $globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $theirGroup = CustomerGroup::create($globex, 'Revendeurs', null, $this->now);
        $this->groups->save($theirGroup);
        $theirCustomer = $this->customerIn($globex, $theirGroup);

        foreach ([['customerGroupId', $this->input('A', group: $theirGroup->getId())], ['customerId', $this->input('B', customer: $theirCustomer->getId())], ['customerId', $this->input('C', customer: Uuid::v7())]] as [$field, $input]) {
            try {
                $this->manage->create($this->company, $input, null);
                self::fail('A list was scoped to something this company does not have.');
            } catch (InvalidPriceList $refused) {
                self::assertSame($field, $refused->field);
            }
        }
        self::assertSame([], $this->manage->list($this->company));
    }

    public function testAListIsForOneCustomerOrOneGroupNotBoth(): void
    {
        $group = CustomerGroup::create($this->company, 'Revendeurs', null, $this->now);
        $this->groups->save($group);
        $customer = $this->customerIn($this->company, $group);

        try {
            $this->manage->create($this->company, $this->input('Les deux', group: $group->getId(), customer: $customer->getId()), null);
            self::fail('A list was scoped to a customer and a group.');
        } catch (InvalidPriceList $refused) {
            self::assertSame('customerId', $refused->field);
        }
    }

    public function testAProductOfAnotherCompanyIsRefusedAtItsRowNaming(): void
    {
        $theirs = $this->productIn(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'ART-009');

        try {
            $this->manage->create($this->company, $this->input('Public', items: [new PriceListItemInput($this->screw->getId(), '1', '1'), new PriceListItemInput($theirs->getId(), '1', '1')]), null);
            self::fail("Another company's product was priced.");
        } catch (InvalidPriceList $refused) {
            self::assertSame('items.1.productId', $refused->field);
        }
        self::assertSame([], $this->manage->list($this->company));
    }

    public function testABadRowNamesItsIndexAndField(): void
    {
        foreach ([['items.0.minQuantity', '0', '1'], ['items.0.minQuantity', '1.2345', '1'], ['items.0.unitPriceNet', '1', '-1'], ['items.0.unitPriceNet', '1', '1.00001'], ['items.0.unitPriceNet', '1', 'abc']] as [$field, $minimum, $price]) {
            try {
                $this->manage->create($this->company, $this->input('X', items: [new PriceListItemInput($this->screw->getId(), $minimum, $price)]), null);
                self::fail('A bad row was accepted: '.$minimum.' '.$price);
            } catch (InvalidPriceList $refused) {
                self::assertSame($field, $refused->field);
            }
        }
    }

    public function testAProductHasOnePricePerMinimumInAList(): void
    {
        try {
            $this->manage->create($this->company, $this->input('Public', items: [new PriceListItemInput($this->screw->getId(), '5', '1'), new PriceListItemInput($this->screw->getId(), '5.0', '2')]), null);
            self::fail('Two prices for one break were accepted.');
        } catch (InvalidPriceList $refused) {
            self::assertSame('items.1.minQuantity', $refused->field);
        }
    }

    public function testTheDaysAreOrderedAndTheNameIsBounded(): void
    {
        foreach ([['validTo', $this->input('A', from: '2026-10-05', to: '2026-10-04')], ['name', $this->input('  ')], ['name', $this->input(str_repeat('é', 121))]] as [$field, $input]) {
            try {
                $this->manage->create($this->company, $input, null);
                self::fail('An invalid list was accepted.');
            } catch (InvalidPriceList $refused) {
                self::assertSame($field, $refused->field);
            }
        }
        $this->manage->create($this->company, $this->input('Un jour', from: '2026-10-05', to: '2026-10-05'), null);
    }

    public function testRevisingAuditsTheFieldNamesThatChangedAndNothingWhenNothingDid(): void
    {
        $list = $this->manage->create($this->company, $this->input('Public', items: [new PriceListItemInput($this->screw->getId(), '1', '10')]), null);
        $count = \count($this->audit->entries);

        $this->manage->revise($this->company, $list->getId(), $this->input('Public', items: [new PriceListItemInput($this->screw->getId(), '1', '10')]), null);
        self::assertCount($count, $this->audit->entries);

        $this->manage->revise($this->company, $list->getId(), $this->input('Public 2', active: false, items: [new PriceListItemInput($this->screw->getId(), '1', '9')]), null);
        $entry = $this->audit->entries[$count];
        self::assertSame(ManagePriceLists::REVISED, $entry->action);
        $fields = $entry->changes['fields'];
        self::assertIsArray($fields);
        sort($fields);
        self::assertSame(['isActive', 'items', 'name'], $fields);
        self::assertSame('9.0000', $list->getItems()[0]->getUnitPriceNet());
    }

    public function testAnOmittedItemsListLeavesThePricesAndAnEmptyOneClearsThem(): void
    {
        $list = $this->manage->create($this->company, $this->input('Public', items: [new PriceListItemInput($this->screw->getId(), '1', '10')]), null);

        $this->manage->revise($this->company, $list->getId(), $this->input('Public', active: false), null);
        self::assertCount(1, $list->getItems());
        $this->manage->revise($this->company, $list->getId(), $this->input('Public', active: false, items: []), null);
        self::assertSame([], $list->getItems());
    }

    public function testAListOfAnotherCompanyIsNotFoundAndDeletingAuditsOnce(): void
    {
        $globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $theirs = $this->manage->create($globex, $this->input('Public'), null);
        try {
            $this->manage->get($this->company, $theirs->getId());
            self::fail("Another company's list was read.");
        } catch (PriceListNotFound) {
        }
        $mine = $this->manage->create($this->company, $this->input('Mine'), null);
        $count = \count($this->audit->entries);

        $this->manage->delete($this->company, $mine->getId(), null);

        self::assertSame([], $this->manage->list($this->company));
        self::assertCount($count + 1, $this->audit->entries);
        self::assertSame(ManagePriceLists::DELETED, $this->audit->entries[$count]->action);
        $this->expectException(PriceListNotFound::class);
        $this->manage->delete($this->company, $theirs->getId(), null);
    }

    /** @param list<PriceListItemInput>|null $items */
    private function input(string $name, ?Uuid $group = null, ?Uuid $customer = null, ?string $from = null, ?string $to = null, bool $active = true, ?array $items = null): PriceListInput
    {
        return new PriceListInput($name, $group, $customer, null === $from ? null : new \DateTimeImmutable($from), null === $to ? null : new \DateTimeImmutable($to), $active, $items);
    }

    private function productIn(Company $company, string $reference): Product
    {
        $piece = Unit::create($company, 'C62', 'pièce', 0, 0, $this->now);
        $product = Product::create($company, $reference, new ProductDetails('Vis', null, ProductKind::Goods, '10', null), $piece, null, [], $this->now);
        $this->products->save($product);

        return $product;
    }

    private function customerIn(Company $company, CustomerGroup $group): Customer
    {
        $customer = Customer::create($company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Maison Durand'), $group, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $this->now), [], $this->now);
        $this->customers->save($customer);

        return $customer;
    }
}

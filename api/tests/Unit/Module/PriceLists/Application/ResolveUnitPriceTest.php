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
use App\Module\PriceLists\Application\ResolveUnitPrice;
use App\Module\PriceLists\Domain\PriceList;
use App\Module\PriceLists\Domain\PriceListItem;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemoryCustomers;
use App\Tests\Support\InMemoryPriceLists;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ResolveUnitPriceTest extends TestCase
{
    private InMemoryPriceLists $lists;
    private InMemoryCustomers $customers;
    private ResolveUnitPrice $resolve;
    private Company $company;
    private Product $product;
    private CustomerGroup $group;
    private Customer $customer;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-02 09:00:00');
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->lists = new InMemoryPriceLists();
        $this->customers = new InMemoryCustomers();
        $this->resolve = new ResolveUnitPrice($this->lists, $this->customers);
        $piece = Unit::create($this->company, 'C62', 'pièce', 0, 0, $this->now);
        $this->product = Product::create($this->company, 'ART-001', new ProductDetails('Vis', null, ProductKind::Goods, '1250', null), $piece, null, [], $this->now);
        $this->group = CustomerGroup::create($this->company, 'Revendeurs', null, $this->now);
        $this->customer = $this->customerIn($this->group, 'CLI-0001');
    }

    public function testAProductNoListPricesKeepsItsShelfPrice(): void
    {
        $price = $this->resolve->of($this->product, $this->customer->getId(), '5', $this->now);

        self::assertSame('1250.0000', $price->unitPriceNet);
        self::assertNull($price->priceListId);
        self::assertNull($price->minQuantity);
    }

    public function testTheCustomersListBeatsTheGroupsWhichBeatsEveryonesEvenWhenItIsDearer(): void
    {
        $everyone = $this->list('Public', null, null, ['1.000' => '100']);
        $group = $this->list('Revendeurs', $this->group->getId(), null, ['1.000' => '90']);
        $own = $this->list('Durand', null, $this->customer->getId(), ['1.000' => '95']);

        self::assertSame($own->getId()->toRfc4122(), $this->winnerFor($this->customer));
        $this->lists->remove($own);
        self::assertSame($group->getId()->toRfc4122(), $this->winnerFor($this->customer));
        $this->lists->remove($group);
        $price = $this->resolve->of($this->product, $this->customer->getId(), '1', $this->now);
        self::assertSame($everyone->getId()->toRfc4122(), $this->winnerFor($this->customer));
        self::assertSame(['100.0000', 'Public'], [$price->unitPriceNet, $price->priceListName]);
    }

    public function testAnonymousSaleIsPricedByTheListForEveryoneOnly(): void
    {
        $this->list('Revendeurs', $this->group->getId(), null, ['1.000' => '10']);
        $this->list('Durand', null, $this->customer->getId(), ['1.000' => '20']);

        self::assertSame('1250.0000', $this->resolve->of($this->product, null, '1', $this->now)->unitPriceNet);
        $this->list('Public', null, null, ['1.000' => '1000']);
        self::assertSame('1000.0000', $this->resolve->of($this->product, null, '1', $this->now)->unitPriceNet);
    }

    public function testAnotherCustomersOrGroupsListNeverApplies(): void
    {
        $other = $this->customerIn(CustomerGroup::create($this->company, 'Particuliers', null, $this->now), 'CLI-0002');
        $this->list('Pour Durand', null, $this->customer->getId(), ['1.000' => '10']);
        $this->list('Pour revendeurs', $this->group->getId(), null, ['1.000' => '20']);

        self::assertSame('1250.0000', $this->resolve->of($this->product, $other->getId(), '1', $this->now)->unitPriceNet);
    }

    public function testTheHighestMinimumTheQuantityReachesPricesIt(): void
    {
        $list = $this->list('Public', null, null, ['1.000' => '100', '10.000' => '90', '50.000' => '80']);

        foreach (['1' => ['100.0000', '1.000'], '9.999' => ['100.0000', '1.000'], '10' => ['90.0000', '10.000'], '49' => ['90.0000', '10.000'], '50' => ['80.0000', '50.000'], '500' => ['80.0000', '50.000']] as $quantity => $expected) {
            $price = $this->resolve->of($this->product, null, (string) $quantity, $this->now);
            self::assertSame($expected, [$price->unitPriceNet, $price->minQuantity], 'quantity '.$quantity);
            self::assertSame($list->getId()->toRfc4122(), $price->priceListId?->toRfc4122());
        }
    }

    public function testBelowEveryBreakTheShelfPriceStands(): void
    {
        $this->list('Public', null, null, ['10.000' => '90']);

        $price = $this->resolve->of($this->product, null, '9', $this->now);

        self::assertSame('1250.0000', $price->unitPriceNet);
        self::assertNull($price->priceListId);
    }

    public function testTwoListsOfTheSameKindGiveTheLowerPriceWhateverTheirOrder(): void
    {
        $this->list('A', null, null, ['1.000' => '80']);
        $this->list('B', null, null, ['1.000' => '70']);
        $this->list('C', null, null, ['1.000' => '75']);
        $first = $this->resolve->of($this->product, null, '1', $this->now)->unitPriceNet;
        $this->lists->lists = array_reverse($this->lists->lists);
        $second = $this->resolve->of($this->product, null, '1', $this->now)->unitPriceNet;

        self::assertSame(['70.0000', '70.0000'], [$first, $second]);
    }

    public function testAListOnlyPricesTheProductsItHolds(): void
    {
        $piece = Unit::create($this->company, 'C62', 'pièce', 0, 0, $this->now);
        $other = Product::create($this->company, 'ART-002', new ProductDetails('Écrou', null, ProductKind::Goods, '300.0000', null), $piece, null, [], $this->now);
        $this->list('Public', null, null, ['1.000' => '100']);

        self::assertSame('300.0000', $this->resolve->of($other, null, '1', $this->now)->unitPriceNet);
    }

    public function testAnInactiveListAndOneOutsideItsDaysAreIgnored(): void
    {
        $this->list('Off', null, null, ['1.000' => '1'], isActive: false);
        $this->list('Future', null, null, ['1.000' => '2'], validFrom: '2026-10-03');
        $this->list('Past', null, null, ['1.000' => '3'], validTo: '2026-10-01');

        self::assertSame('1250.0000', $this->resolve->of($this->product, null, '1', $this->now)->unitPriceNet);
    }

    public function testTheFirstAndLastDaysOfAListAreInside(): void
    {
        $this->list('Week', null, null, ['1.000' => '50'], validFrom: '2026-10-02', validTo: '2026-10-02');

        self::assertSame('50.0000', $this->resolve->of($this->product, null, '1', new \DateTimeImmutable('2026-10-02 23:59:59'))->unitPriceNet);
        self::assertSame('1250.0000', $this->resolve->of($this->product, null, '1', new \DateTimeImmutable('2026-10-03 00:00:00'))->unitPriceNet);
        self::assertSame('1250.0000', $this->resolve->of($this->product, null, '1', new \DateTimeImmutable('2026-10-01 23:59:59'))->unitPriceNet);
    }

    public function testAListOfAnotherCompanyNeverPricesThisOne(): void
    {
        $globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $theirs = PriceList::create($globex, 'Public', null, null, null, null, true, $this->now);
        $this->lists->save($theirs);

        self::assertSame('1250.0000', $this->resolve->of($this->product, null, '1', $this->now)->unitPriceNet);
    }

    /**
     * @param array<string, string> $rows minimum quantity => net unit price
     */
    private function list(string $name, ?Uuid $group, ?Uuid $customer, array $rows, bool $isActive = true, ?string $validFrom = null, ?string $validTo = null): PriceList
    {
        $list = PriceList::create($this->company, $name, $group, $customer, null === $validFrom ? null : new \DateTimeImmutable($validFrom), null === $validTo ? null : new \DateTimeImmutable($validTo), $isActive, $this->now);
        $items = [];
        foreach ($rows as $minimum => $price) {
            $items[] = new PriceListItem($list, $this->product, (string) $minimum, $price);
        }
        $list->replaceItems($items, $this->now);
        $this->lists->save($list);

        return $list;
    }

    /** The id of the list that prices one unit for the customer, null for the shelf price. */
    private function winnerFor(Customer $customer): ?string
    {
        return $this->resolve->of($this->product, $customer->getId(), '1', $this->now)->priceListId?->toRfc4122();
    }

    private function customerIn(CustomerGroup $group, string $number): Customer
    {
        $customer = Customer::create($this->company, $number, new CustomerProfile(CustomerKind::Company, 'Maison '.$number), $group, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $this->now), [], $this->now);
        $this->customers->save($customer);

        return $customer;
    }
}

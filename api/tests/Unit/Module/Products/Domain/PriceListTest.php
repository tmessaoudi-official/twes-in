<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Domain;

use App\Fiscal\Domain\Unit;
use App\Module\Products\Domain\InvalidPriceList;
use App\Module\Products\Domain\PriceList;
use App\Module\Products\Domain\PriceListItem;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PriceListTest extends TestCase
{
    private Company $company;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->now = new \DateTimeImmutable('2026-10-02 09:00:00');
    }

    public function testARowPricesOnlyAProductOfItsListsCompany(): void
    {
        $list = PriceList::create($this->company, 'Public', null, null, null, null, true, $this->now);
        $theirs = $this->product(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'ART-009');

        try {
            new PriceListItem($list, $theirs, '1', '1');
            self::fail("Another company's product was priced.");
        } catch (InvalidPriceList $refused) {
            self::assertSame('productId', $refused->field);
        }
    }

    public function testRowsAreNormalizedToTheirColumnsScale(): void
    {
        $item = new PriceListItem(PriceList::create($this->company, 'Public', null, null, null, null, true, $this->now), $this->product($this->company, 'ART-001'), ' 2.5 ', '0');

        self::assertSame(['2.500', '0.0000'], [$item->getMinQuantity(), $item->getUnitPriceNet()]);
    }

    public function testReplacingWithTheSameRowsInAnotherOrderChangesNothing(): void
    {
        $list = PriceList::create($this->company, 'Public', null, null, null, null, true, $this->now);
        $a = $this->product($this->company, 'ART-001');
        $b = $this->product($this->company, 'ART-002');
        self::assertTrue($list->replaceItems([new PriceListItem($list, $a, '1', '5'), new PriceListItem($list, $b, '1', '6')], $this->now));

        self::assertFalse($list->replaceItems([new PriceListItem($list, $b, '1', '6'), new PriceListItem($list, $a, '1', '5')], $this->now));
        self::assertTrue($list->replaceItems([new PriceListItem($list, $b, '1', '6'), new PriceListItem($list, $a, '1', '4')], $this->now));
    }

    public function testAListHoldsAtMostItsLimitOfPrices(): void
    {
        $list = PriceList::create($this->company, 'Public', null, null, null, null, true, $this->now);
        $product = $this->product($this->company, 'ART-001');
        $rows = [];
        for ($i = 1; $i <= PriceList::ITEMS_MAX + 1; ++$i) {
            $rows[] = new PriceListItem($list, $product, (string) $i, '1');
        }

        $this->expectException(InvalidPriceList::class);
        $list->replaceItems($rows, $this->now);
    }

    public function testRevisingTouchesItsDateOnlyWhenSomethingChanged(): void
    {
        $list = PriceList::create($this->company, 'Public', null, null, null, null, true, $this->now);
        $later = $this->now->modify('+1 day');

        self::assertFalse($list->revise(' Public ', null, null, null, null, true, $later));
        self::assertSame($this->now, $list->getUpdatedAt());
        self::assertTrue($list->revise('Public', Uuid::v7(), null, null, null, true, $later));
        self::assertSame($later, $list->getUpdatedAt());
    }

    private function product(Company $company, string $reference): Product
    {
        $piece = Unit::create($company, 'C62', 'pièce', 0, 0, $this->now);

        return Product::create($company, $reference, new ProductDetails('Vis', null, ProductKind::Goods, '10', null), $piece, null, [], $this->now);
    }
}

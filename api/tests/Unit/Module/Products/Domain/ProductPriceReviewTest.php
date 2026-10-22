<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Domain;

use App\Fiscal\Domain\Model\Unit;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Tenancy\Domain\Model\Company;
use PHPUnit\Framework\TestCase;

/**
 * A cost that moves under a kept price moves the margin: the product keeps the cost its price was set against until
 * someone adjusts the price, keeps it, or the cost comes back.
 */
final class ProductPriceReviewTest extends TestCase
{
    private Company $company;
    private Unit $piece;
    private \DateTimeImmutable $now;

    public function testACostMovedUnderAKeptPriceOpensAReviewHoldingTheCostThePriceWasSetAgainst(): void
    {
        $product = $this->product('18.462', '12');
        $product->reviseCost('13', $this->now);

        self::assertTrue($product->openPriceReview('12.0000', $this->now));

        $review = $product->priceReview();
        self::assertNotNull($review);
        self::assertSame(['12.0000', '13.0000', '18.4620'], [$review->previousCost, $review->cost, $review->price]);
        // 18.462 ÷ 12 × 13, rounded to the dinar's three decimals: the same margin on the new cost.
        self::assertSame('20.001', $review->suggestedPrice(3));
        // (18.462 − 12) ÷ 18.462 and (18.462 − 13) ÷ 18.462, as percentages of the price.
        self::assertSame(['35.0', '29.6'], [$review->previousMargin(), $review->margin()]);
    }

    public function testACostAboveThePriceIsANegativeMargin(): void
    {
        $product = $this->product('10', '8');
        $product->reviseCost('12.5', $this->now);
        $product->openPriceReview('8.0000', $this->now);

        self::assertSame(['20.0', '-25.0'], [$product->priceReview()?->previousMargin(), $product->priceReview()?->margin()]);
    }

    public function testASecondMoveKeepsTheFirstCostSoTheWholeDriftIsShown(): void
    {
        $product = $this->product('18.462', '12');
        $product->reviseCost('13', $this->now);
        $product->openPriceReview('12.0000', $this->now);
        $product->reviseCost('14', $this->now);

        self::assertFalse($product->openPriceReview('13.0000', $this->now), 'one review at a time: it is already open');
        $review = $product->priceReview();
        self::assertNotNull($review);
        self::assertSame(['12.0000', '14.0000'], [$review->previousCost, $review->cost]);
    }

    public function testNoReviewOpensWithoutAPreviousCostOrAPrice(): void
    {
        $free = $this->product('0', '12');
        $free->reviseCost('13', $this->now);
        self::assertFalse($free->openPriceReview('12.0000', $this->now), 'no price, no margin to move');

        $first = $this->product('18.462', null);
        $first->reviseCost('13', $this->now);
        self::assertFalse($first->openPriceReview(null, $this->now), 'a first cost moves no margin');
        self::assertFalse($first->openPriceReview('0', $this->now), 'nor does a cost of nothing');
        self::assertNull($first->priceReview());
    }

    public function testTheCostComingBackClosesTheReview(): void
    {
        $product = $this->product('18.462', '12');
        $product->reviseCost('13', $this->now);
        $product->openPriceReview('12.0000', $this->now);

        $product->reviseCost('12.000', $this->now);

        self::assertNull($product->priceReview());
    }

    public function testAdjustingSetsThePriceThatKeepsTheMarginAndCloses(): void
    {
        $product = $this->product('18.462', '12');
        $product->reviseCost('13', $this->now);
        $product->openPriceReview('12.0000', $this->now);

        self::assertSame('20.001', $product->adjustPriceToReview(3, $this->now));

        self::assertSame('20.0010', $product->getDetails()->unitPriceNet);
        self::assertNull($product->priceReview());
    }

    public function testKeepingThePriceClosesTheReviewAndLeavesThePrice(): void
    {
        $product = $this->product('18.462', '12');
        $product->reviseCost('13', $this->now);
        $product->openPriceReview('12.0000', $this->now);

        self::assertTrue($product->closePriceReview($this->now));

        self::assertSame('18.4620', $product->getDetails()->unitPriceNet);
        self::assertNull($product->priceReview());
        self::assertFalse($product->closePriceReview($this->now), 'nothing left to close');
    }

    public function testAdjustingWithNoReviewOpenIsRefused(): void
    {
        $product = $this->product('18.462', '12');

        $this->expectException(\LogicException::class);
        $product->adjustPriceToReview(3, $this->now);
    }

    public function testAPriceTypedInTheProductClosesTheReview(): void
    {
        $product = $this->product('18.462', '12');
        $product->reviseCost('13', $this->now);
        $product->openPriceReview('12.0000', $this->now);

        $product->revise('ART-001', new ProductDetails('Ampoule', null, ProductKind::Goods, '19.5', '13'), $this->piece, null, [], true, $this->now);

        self::assertNull($product->priceReview());
    }

    public function testARevisionLeavingThePriceKeepsTheReview(): void
    {
        $product = $this->product('18.462', '12');
        $product->reviseCost('13', $this->now);
        $product->openPriceReview('12.0000', $this->now);

        $product->revise('ART-001', new ProductDetails('Ampoule E27', null, ProductKind::Goods, '18.462', '13'), $this->piece, null, [], true, $this->now);

        self::assertSame('12.0000', $product->priceReview()?->previousCost);
    }

    public function testTakingTheCostAwayClosesTheReview(): void
    {
        $product = $this->product('18.462', '12');
        $product->reviseCost('13', $this->now);
        $product->openPriceReview('12.0000', $this->now);

        $product->reviseCost(null, $this->now);

        self::assertNull($product->priceReview());
    }

    /** A price that followed its cost may be put back while it is the one the follow set, and that once. */
    public function testAPriceThatFollowedItsCostIsPutBackToThePriceItReplaced(): void
    {
        $product = $this->product('18.462', '12');
        $product->reviseCost('13', $this->now);
        $product->openPriceReview('12.0000', $this->now);

        self::assertSame('20.001', $product->followCostWithPrice(3, $this->now));
        self::assertNull($product->priceReview());

        self::assertSame('18.4620', $product->restoreFollowedPrice($this->now));
        self::assertSame(['18.4620', '13.0000', null], [$product->getDetails()->unitPriceNet, $product->getDetails()->costPrice, $product->priceReview()], 'the price as it was, under the cost the receipt left: kept, as « Garder le prix » would');

        $this->expectException(\LogicException::class);
        $product->restoreFollowedPrice($this->now);
    }

    public function testAPriceMovedSinceItFollowedItsCostIsNoLongerPutBack(): void
    {
        $product = $this->product('18.462', '12');
        $product->reviseCost('13', $this->now);
        $product->openPriceReview('12.0000', $this->now);
        $product->followCostWithPrice(3, $this->now);

        $product->revise('ART-001', new ProductDetails('Ampoule', null, ProductKind::Goods, '21', '13'), $this->piece, null, [], true, $this->now);

        self::assertFalse($product->mayRestoreFollowedPrice());
        $this->expectException(\LogicException::class);
        $product->restoreFollowedPrice($this->now);
    }

    public function testARevisionLeavingTheFollowedPriceKeepsItsWayBack(): void
    {
        $product = $this->product('18.462', '12');
        $product->reviseCost('13', $this->now);
        $product->openPriceReview('12.0000', $this->now);
        $product->followCostWithPrice(3, $this->now);

        $product->revise('ART-001', new ProductDetails('Ampoule E27', null, ProductKind::Goods, '20.001', '13'), $this->piece, null, [], true, $this->now);

        self::assertTrue($product->mayRestoreFollowedPrice());
    }

    public function testAPriceAdjustedFromAReviewHasNoWayBack(): void
    {
        $product = $this->product('18.462', '12');
        $product->reviseCost('13', $this->now);
        $product->openPriceReview('12.0000', $this->now);

        $product->adjustPriceToReview(3, $this->now);

        self::assertFalse($product->mayRestoreFollowedPrice(), 'a person chose that price: undoing it is typing the old one');
    }

    protected function setUp(): void
    {
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->now = new \DateTimeImmutable('2026-10-21 01:00:00');
        $this->piece = Unit::create($this->company, 'C62', 'pièce', 0, 0, $this->now);
    }

    private function product(string $price, ?string $cost): Product
    {
        return Product::create($this->company, 'ART-001', new ProductDetails('Ampoule', null, ProductKind::Goods, $price, $cost), $this->piece, null, [], $this->now);
    }
}

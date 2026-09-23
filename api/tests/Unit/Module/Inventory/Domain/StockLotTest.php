<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Domain;

use App\Fiscal\Domain\Unit;
use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Module\Inventory\Domain\StockLot;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StockLotTest extends TestCase
{
    private \DateTimeImmutable $now;
    private Product $glue;
    private Product $screws;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-23 09:00:00');
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $piece = Unit::create($company, 'C62', 'Pièce', 0, 1, $this->now);
        $this->glue = Product::create($company, 'COL-01', new ProductDetails('Colle', null, ProductKind::Goods, '12'), $piece, null, [], $this->now);
        $this->glue->track(ProductTracking::Lot, $this->now);
        $this->screws = Product::create($company, 'VIS-01', new ProductDetails('Vis', null, ProductKind::Goods, '1'), $piece, null, [], $this->now);
    }

    public function testALotIsOpenedForATrackedProductUnderItsCodeAndUseByDate(): void
    {
        $lot = StockLot::open($this->glue, ' L2609-A ', new \DateTimeImmutable('2027-03-31'), $this->now);

        self::assertSame([$this->glue, $this->glue->getCompany(), 'L2609-A', '2027-03-31', $this->now], [$lot->getProduct(), $lot->getCompany(), $lot->getCode(), $lot->getExpiresOn()?->format('Y-m-d'), $lot->getCreatedAt()]);
        self::assertNull(StockLot::open($this->glue, 'L2', null, $this->now)->getExpiresOn());
    }

    public function testAProductTrackingNothingHasNoLot(): void
    {
        $this->expectExceptionObject(new InvalidStockMovement('lot', 'The product VIS-01 is not tracked by lot or serial number: its stock names no lot.'));

        StockLot::open($this->screws, 'L1', null, $this->now);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'a space inside' => ['L 1'];
        yield 'forty-one characters' => [str_repeat('A', 41)];
        yield 'a group separator' => ["L1\x1D17"];
        yield 'an accent' => ['LOTÉ'];
    }

    #[DataProvider('refusedCodes')]
    public function testACodeIsOneToFortyPrintableCharactersWithoutSpace(string $code): void
    {
        try {
            StockLot::open($this->glue, $code, null, $this->now);
            self::fail('The code was accepted.');
        } catch (InvalidStockMovement $refused) {
            self::assertSame('lotCode', $refused->field);
        }
        self::assertSame(str_repeat('A', 40), StockLot::open($this->glue, str_repeat('A', 40), null, $this->now)->getCode());
    }

    public function testADateFillsALotWithoutOneAndMustAgreeWithALotThatHasOne(): void
    {
        $undated = StockLot::open($this->glue, 'L1', null, $this->now);
        self::assertFalse($undated->dated(null));
        self::assertTrue($undated->dated(new \DateTimeImmutable('2027-01-31')));
        self::assertSame('2027-01-31', $undated->getExpiresOn()?->format('Y-m-d'));
        self::assertFalse($undated->dated(new \DateTimeImmutable('2027-01-31 18:00')), 'the same day is the same date');
        self::assertFalse($undated->dated(null), 'saying nothing keeps the date');

        try {
            $undated->dated(new \DateTimeImmutable('2027-02-28'));
            self::fail('A second date was accepted.');
        } catch (InvalidStockMovement $refused) {
            self::assertSame('lotExpiresOn', $refused->field);
            self::assertStringContainsString('2027-01-31', $refused->getMessage());
        }
        self::assertSame('2027-01-31', $undated->getExpiresOn()->format('Y-m-d'));
    }
}

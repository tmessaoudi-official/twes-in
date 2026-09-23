<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Domain;

use App\Fiscal\Domain\Unit;
use App\Module\Inventory\Domain\LotOnHand;
use App\Module\Inventory\Domain\LotPicking;
use App\Module\Inventory\Domain\StockLot;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class LotPickingTest extends TestCase
{
    private \DateTimeImmutable $today;
    private Product $glue;

    protected function setUp(): void
    {
        $this->today = new \DateTimeImmutable('2026-09-23');
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $piece = Unit::create($company, 'C62', 'Pièce', 0, 1, $this->today);
        $this->glue = Product::create($company, 'COL-01', new ProductDetails('Colle', null, ProductKind::Goods, '12'), $piece, null, [], $this->today);
        $this->glue->track(ProductTracking::Lot, $this->today);
    }

    public function testTheFirstToExpireLeavesFirstAndALineSpansLotsWhenItMust(): void
    {
        $picked = LotPicking::firstExpiring([
            $this->onHand('UNDATED', null, '3'),
            $this->onHand('NOVEMBER', '2026-11-01', '10'),
            $this->onHand('OCTOBER', '2026-10-01', '5'),
        ], '8', $this->today);

        self::assertSame([['OCTOBER', '5.000'], ['NOVEMBER', '3.000']], $this->taken($picked->taken));
        self::assertSame('0.000', $picked->short);
        self::assertSame([['OCTOBER', '5.000'], ['NOVEMBER', '10.000'], ['UNDATED', '3.000']], $this->taken(LotPicking::firstExpiring([
            $this->onHand('UNDATED', null, '3'),
            $this->onHand('NOVEMBER', '2026-11-01', '10'),
            $this->onHand('OCTOBER', '2026-10-01', '5'),
        ], '18', $this->today)->taken), 'a lot without a date leaves last');
    }

    public function testAnExpiredLotStaysUnlessReleasedAndWhatNoLotInDateHoldsIsShort(): void
    {
        $expired = $this->onHand('AUGUST', '2026-08-31', '4');
        $candidates = [$expired, $this->onHand('OCTOBER', '2026-10-01', '2'), $this->onHand('EMPTY', '2026-09-01', '0'), $this->onHand('OWED', '2026-09-02', '-1')];

        $picked = LotPicking::firstExpiring($candidates, '5', $this->today);
        self::assertSame([['OCTOBER', '2.000']], $this->taken($picked->taken));
        self::assertSame('3.000', $picked->short);

        $expired->lot->release(Uuid::v7(), $this->today);
        $released = LotPicking::firstExpiring($candidates, '5', $this->today);
        self::assertSame([['AUGUST', '4.000'], ['OCTOBER', '1.000']], $this->taken($released->taken));
        self::assertSame('0.000', $released->short);
    }

    public function testEqualDatesLeaveInTheOrderTheLotsArrived(): void
    {
        $first = $this->onHand('B-FIRST', '2026-10-01', '1');
        $later = new LotOnHand(StockLot::open($this->glue, 'A-LATER', new \DateTimeImmutable('2026-10-01'), $this->today->modify('+1 day')), '1.000');

        self::assertSame([['B-FIRST', '1.000']], $this->taken(LotPicking::firstExpiring([$later, $first], '1', $this->today)->taken));
    }

    /** @param numeric-string $quantity */
    private function onHand(string $code, ?string $expiresOn, string $quantity): LotOnHand
    {
        return new LotOnHand(StockLot::open($this->glue, $code, null === $expiresOn ? null : new \DateTimeImmutable($expiresOn), $this->today), $quantity);
    }

    /**
     * @param list<array{StockLot, numeric-string}> $taken
     *
     * @return list<array{string, string}>
     */
    private function taken(array $taken): array
    {
        return array_map(static fn (array $each): array => [$each[0]->getCode(), $each[1]], $taken);
    }
}

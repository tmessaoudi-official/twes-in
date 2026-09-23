<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Domain;

use App\Fiscal\Domain\Unit;
use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Module\Inventory\Domain\StockLot;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementKind;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class StockMovementTest extends TestCase
{
    private \DateTimeImmutable $now;
    private Company $company;
    private StockLocation $site;
    private Product $laptop;
    private Product $flour;
    private Product $support;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-15 09:00:00');
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->site = StockLocation::defaultOf(Establishment::create($this->company, '000', 'Siège', true, $this->now), $this->now);
        $piece = Unit::create($this->company, 'C62', 'Pièce', 0, 1, $this->now);
        $kilogram = Unit::create($this->company, 'KGM', 'Kilogramme', 3, 2, $this->now);
        $this->laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '1250'), $piece, null, [], $this->now);
        $this->flour = Product::create($this->company, 'ART-002', new ProductDetails('Farine', null, ProductKind::Goods, '2'), $kilogram, null, [], $this->now);
        $this->support = Product::create($this->company, 'SRV-001', new ProductDetails('Assistance', null, ProductKind::Service, '50'), $piece, null, [], $this->now);
    }

    public function testEveryMovementOfATrackedProductNamesItsLotAndOfAnUntrackedOneNone(): void
    {
        $this->laptop->track(ProductTracking::Lot, $this->now);
        $lot = StockLot::open($this->laptop, 'L1', null, $this->now);
        $shelf = StockLocation::create($this->site->getEstablishment(), $this->site, StockLocationKind::Zone, 'Z1', 'Zone', $this->now);

        self::assertSame($lot, StockMovement::receipt($this->laptop, $this->site, '2', null, $this->now, $lot)->getLot());
        self::assertSame($lot, StockMovement::count($this->laptop, $this->site, '1', '2.000', null, $this->now, $lot)->getLot());
        [$out, $in] = StockMovement::move($this->laptop, $this->site, $shelf, '1', null, $this->now, $lot);
        self::assertSame([$lot, $lot], [$out->getLot(), $in->getLot()]);
        self::assertNull(StockMovement::receipt($this->flour, $this->site, '2', null, $this->now)->getLot());

        $flourLot = StockLot::open(self::tracked($this->flour, $this->now), 'F1', null, $this->now);
        $this->flour->track(ProductTracking::None, $this->now);
        foreach ([
            'a tracked product without its lot' => fn () => StockMovement::receipt($this->laptop, $this->site, '1', null, $this->now),
            'a count of it without its lot' => fn () => StockMovement::count($this->laptop, $this->site, '1', '0', null, $this->now),
            'a move of it without its lot' => fn () => StockMovement::move($this->laptop, $this->site, $shelf, '1', null, $this->now),
            'a delivery of it without its lot' => fn () => StockMovement::delivery($this->laptop, $this->site, '1', Uuid::v7(), $this->now),
            'an untracked product with a lot' => fn () => StockMovement::receipt($this->flour, $this->site, '1', null, $this->now, $flourLot),
            'another product\'s lot' => fn () => StockMovement::receipt($this->laptop, $this->site, '1', null, $this->now, $flourLot),
        ] as $case => $movement) {
            try {
                $movement();
                self::fail(\sprintf('%s was accepted.', $case));
            } catch (InvalidStockMovement $refused) {
                self::assertSame('lot', $refused->field, $case);
            }
        }
    }

    public function testASerialNumberMovesOnePieceAtATime(): void
    {
        $this->laptop->track(ProductTracking::Serial, $this->now);
        $serial = StockLot::open($this->laptop, 'SN-0001', null, $this->now);
        $shelf = StockLocation::create($this->site->getEstablishment(), $this->site, StockLocationKind::Zone, 'Z1', 'Zone', $this->now);

        self::assertSame('1.000', StockMovement::receipt($this->laptop, $this->site, '1', null, $this->now, $serial)->getQuantity());
        self::assertSame('-1.000', StockMovement::count($this->laptop, $this->site, '0', '1.000', null, $this->now, $serial)->getQuantity());
        self::assertSame('1.000', StockMovement::move($this->laptop, $this->site, $shelf, '1', null, $this->now, $serial)[1]->getQuantity());
        foreach ([
            'two pieces received' => fn () => StockMovement::receipt($this->laptop, $this->site, '2', null, $this->now, $serial),
            'two pieces counted' => fn () => StockMovement::count($this->laptop, $this->site, '2', '1.000', null, $this->now, $serial),
            'two pieces moved' => fn () => StockMovement::move($this->laptop, $this->site, $shelf, '2', null, $this->now, $serial),
        ] as $case => $movement) {
            try {
                $movement();
                self::fail(\sprintf('%s was accepted.', $case));
            } catch (InvalidStockMovement $refused) {
                self::assertSame('quantity', $refused->field, $case);
            }
        }
    }

    public function testADeliveryReturnedGoesBackToTheLotItCameFrom(): void
    {
        $this->laptop->track(ProductTracking::Lot, $this->now);
        $lot = StockLot::open($this->laptop, 'L1', null, $this->now);

        self::assertSame($lot, StockMovement::returnOf(StockMovement::delivery($this->laptop, $this->site, '1', Uuid::v7(), $this->now, $lot), $this->now)->getLot());
    }

    private static function tracked(Product $product, \DateTimeImmutable $now): Product
    {
        $product->track(ProductTracking::Lot, $now);

        return $product;
    }

    public function testAReceiptAddsGoodsInTheProductsUnitRecordingWhoReceivedThem(): void
    {
        $actor = Uuid::v7();

        $received = StockMovement::receipt($this->laptop, $this->site, '12', $actor, $this->now);

        self::assertSame([StockMovementKind::In, '12.000', StockMovement::SOURCE_RECEIPT, null, $actor], [$received->getKind(), $received->getQuantity(), $received->getSourceType(), $received->getSourceId(), $received->getRecordedBy()]);
        self::assertSame([$this->laptop, $this->site, $this->now], [$received->getProduct(), $received->getLocation(), $received->getAt()]);
        self::assertSame('2.500', StockMovement::receipt($this->flour, $this->site, '2.5', null, $this->now)->getQuantity());
        self::assertSame('3.000', StockMovement::receipt($this->laptop, $this->site, '3.00', null, $this->now)->getQuantity(), 'trailing zeros are no finer than the unit');
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedReceipts(): iterable
    {
        yield 'nothing' => ['ART-001', '0'];
        yield 'a negative quantity' => ['ART-001', '-1'];
        yield 'half a piece' => ['ART-001', '1.5'];
        yield 'not a number' => ['ART-001', 'douze'];
        yield 'an empty quantity' => ['ART-001', ''];
        yield 'twelve integer digits' => ['ART-001', '100000000000'];
        yield 'four decimals of a kilogram' => ['ART-002', '1.2345'];
    }

    #[DataProvider('refusedReceipts')]
    public function testAReceiptOfNothingOrFinerThanItsUnitIsRefused(string $reference, string $quantity): void
    {
        $product = 'ART-001' === $reference ? $this->laptop : $this->flour;

        try {
            StockMovement::receipt($product, $this->site, $quantity, null, $this->now);
            self::fail('The receipt was accepted.');
        } catch (InvalidStockMovement $refused) {
            self::assertSame('quantity', $refused->field);
        }
    }

    public function testACountRecordsTheDifferenceFromTheStockItExpectedEvenNone(): void
    {
        $actor = Uuid::v7();

        $short = StockMovement::count($this->laptop, $this->site, '10', '12.000', $actor, $this->now);

        self::assertSame([StockMovementKind::Adjustment, '-2.000', StockMovement::SOURCE_COUNT, $actor], [$short->getKind(), $short->getQuantity(), $short->getSourceType(), $short->getRecordedBy()]);
        self::assertSame('0.000', StockMovement::count($this->laptop, $this->site, '12', '12.000', $actor, $this->now)->getQuantity());
        self::assertSame('1.250', StockMovement::count($this->flour, $this->site, '1.25', '0.000', $actor, $this->now)->getQuantity());
        self::assertSame('5.000', StockMovement::count($this->laptop, $this->site, '0', '-5.000', $actor, $this->now)->getQuantity(), 'a count of nothing sets a negative stock right');
        foreach (['0.5', '-1'] as $counted) {
            try {
                StockMovement::count($this->laptop, $this->site, $counted, '0.000', $actor, $this->now);
                self::fail(\sprintf('A count of %s pieces was accepted.', $counted));
            } catch (InvalidStockMovement $refused) {
                self::assertSame('quantity', $refused->field);
            }
        }
    }

    public function testADeliveryTakesGoodsOutAndItsReturnBringsTheSameBack(): void
    {
        $noteId = Uuid::v7();

        $out = StockMovement::delivery($this->laptop, $this->site, '3', $noteId, $this->now);
        $back = StockMovement::returnOf($out, $this->now);

        self::assertSame([StockMovementKind::Out, '-3.000', StockMovement::SOURCE_DELIVERY_NOTE, $noteId, null], [$out->getKind(), $out->getQuantity(), $out->getSourceType(), $out->getSourceId(), $out->getRecordedBy()]);
        self::assertSame([StockMovementKind::In, '3.000', StockMovement::SOURCE_DELIVERY_NOTE, $noteId, $this->laptop, $this->site], [$back->getKind(), $back->getQuantity(), $back->getSourceType(), $back->getSourceId(), $back->getProduct(), $back->getLocation()]);
        $this->expectException(\LogicException::class);
        StockMovement::returnOf(StockMovement::receipt($this->laptop, $this->site, '1', null, $this->now), $this->now);
    }

    public function testOnlyGoodsMoveAndOnlyInALocationOfTheirCompany(): void
    {
        $theirs = StockLocation::defaultOf(Establishment::create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), '000', 'Globex', true, $this->now), $this->now);

        foreach ([[$this->support, $this->site, 'productId'], [$this->laptop, $theirs, 'locationId']] as [$product, $location, $field]) {
            try {
                StockMovement::receipt($product, $location, '1', null, $this->now);
                self::fail('The goods moved.');
            } catch (InvalidStockMovement $refused) {
                self::assertSame($field, $refused->field);
            }
        }
    }

    public function testAMoveTakesGoodsOutOfOneLocationAndIntoAnotherAsOneLinkedPair(): void
    {
        $actor = Uuid::v7();
        $rack = StockLocation::create($this->site->getEstablishment(), $this->site, StockLocationKind::Rack, 'R1', 'Rack 1', $this->now);

        [$out, $in] = StockMovement::move($this->laptop, $this->site, $rack, '4', $actor, $this->now);

        // One move id on both halves: that link is what lets the list show them as one move rather than two.
        self::assertNotNull($out->getSourceId());
        self::assertTrue($out->getSourceId()->equals($in->getSourceId() ?? Uuid::v7()));
        self::assertSame([StockMovementKind::Out, '-4.000', StockMovement::SOURCE_MOVE, $this->site, $actor], [$out->getKind(), $out->getQuantity(), $out->getSourceType(), $out->getLocation(), $out->getRecordedBy()]);
        self::assertSame([StockMovementKind::In, '4.000', StockMovement::SOURCE_MOVE, $rack, $actor], [$in->getKind(), $in->getQuantity(), $in->getSourceType(), $in->getLocation(), $in->getRecordedBy()]);
        self::assertSame([$this->now, $this->now], [$out->getAt(), $in->getAt()]);
    }

    public function testAMoveIsRefusedWhereItWouldMoveNothingOrLeaveItsEstablishment(): void
    {
        $rack = StockLocation::create($this->site->getEstablishment(), $this->site, StockLocationKind::Rack, 'R1', 'Rack 1', $this->now);
        $elsewhere = StockLocation::defaultOf(Establishment::create($this->company, '001', 'Dépôt', false, $this->now), $this->now);

        $refused = [
            // Goods already there have not moved, and a pair on one location would double-count on every level.
            'toLocationId' => [$this->site, $this->site],
            // "A move inside an establishment" (§ 7 2026-09-19 23:25); between them comes later, with a transport document.
            'toLocationId2' => [$this->site, $elsewhere],
        ];
        foreach ($refused as [$from, $to]) {
            try {
                StockMovement::move($this->laptop, $from, $to, '1', null, $this->now);
                self::fail('The move was allowed.');
            } catch (InvalidStockMovement $caught) {
                self::assertSame('toLocationId', $caught->field);
            }
        }

        // And a quantity of none is not a move: a count is what records "nothing here".
        try {
            StockMovement::move($this->laptop, $this->site, $rack, '0', null, $this->now);
            self::fail('Nothing moved, and it was allowed.');
        } catch (InvalidStockMovement $caught) {
            self::assertSame('quantity', $caught->field);
        }
    }
}

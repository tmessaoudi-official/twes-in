<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Application;

use App\Fiscal\Domain\Unit;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Module\Inventory\Domain\NamedLot;
use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Module\Inventory\Domain\StockLot;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementKind;
use App\Module\Inventory\Domain\StockMovementSearch;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductCategory;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Shared\Application\LiveChange;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryProducts;
use App\Tests\Support\InMemorySettings;
use App\Tests\Support\InMemoryStockLocations;
use App\Tests\Support\InMemoryStockLots;
use App\Tests\Support\InMemoryStockMovements;
use App\Tests\Support\RecordingLiveChanges;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class KeepStockTest extends TestCase
{
    private const string TRACKING = 'article.stock_tracking';

    private MockClock $clock;
    private InMemoryStockMovements $movements;
    private InMemoryStockLots $lots;
    private InMemoryProducts $products;
    private InMemorySettings $settings;
    private FakeTransactions $transactions;
    private RecordingLiveChanges $liveChanges;
    private KeepStock $keep;
    private InMemoryStockLocations $locations;
    private Company $company;
    private StockLocation $site;
    private ProductCategory $accessories;
    private Product $laptop;
    private Product $cable;
    private Product $support;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-15 09:00:00');
        $now = $this->clock->now();
        $this->movements = new InMemoryStockMovements();
        $this->products = new InMemoryProducts();
        $this->settings = new InMemorySettings();
        $this->transactions = new FakeTransactions();
        $this->movements->transactions = $this->transactions;
        $this->lots = new InMemoryStockLots();
        $this->lots->transactions = $this->transactions;
        $locations = $this->locations = new InMemoryStockLocations();
        $establishments = new InMemoryEstablishments();
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $main = Establishment::create($this->company, '000', 'Siège', true, $now);
        $establishments->save($main);
        $this->site = new ManageStockLocations($locations, $this->movements, $establishments, new InMemoryAuditTrail($this->transactions), $this->clock, $this->transactions)->defaultOf($main);
        $piece = Unit::create($this->company, 'C62', 'Pièce', 0, 1, $now);
        $this->accessories = ProductCategory::create($this->company, 'Accessoires', null, $now);
        $this->laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '1250'), $piece, null, [], $now);
        $this->cable = Product::create($this->company, 'ART-002', new ProductDetails('Câble', null, ProductKind::Goods, '9'), $piece, $this->accessories, [], $now);
        $this->support = Product::create($this->company, 'SRV-001', new ProductDetails('Assistance', null, ProductKind::Service, '50'), $piece, null, [], $now);
        foreach ([$this->laptop, $this->cable, $this->support] as $product) {
            $this->products->save($product);
        }
        $read = new ReadSetting(new ResolveSettings(new SettingCatalog([new BusinessDefaultSettings()]), $this->settings));
        $this->liveChanges = new RecordingLiveChanges($this->transactions);
        $this->keep = new KeepStock($this->movements, $this->lots, $locations, $this->products, $read, $this->transactions, $this->clock, $this->liveChanges);
    }

    public function testOnlyGoodsWhoseStockTrackingResolvesOnAreTracked(): void
    {
        self::assertFalse($this->keep->tracked($this->laptop), 'stock tracking is off by default');

        $this->track(SettingAddress::company($this->company), true);
        self::assertSame([true, true, false], [$this->keep->tracked($this->laptop), $this->keep->tracked($this->cable), $this->keep->tracked($this->support)]);

        $this->track(SettingAddress::product($this->company, $this->laptop->getId()), false);
        $this->track(SettingAddress::company($this->company), false);
        $this->track(SettingAddress::productCategory($this->company, $this->accessories->getId()), true);
        self::assertSame([false, true], [$this->keep->tracked($this->laptop), $this->keep->tracked($this->cable)]);
    }

    public function testReceiptsOfATrackedProductAddUpAtTheirLocation(): void
    {
        $this->track(SettingAddress::company($this->company), true);
        $actor = Uuid::v7();

        $received = $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '5', $actor);
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '2', $actor);

        self::assertSame([StockMovementKind::In, '5.000', $actor], [$received->getKind(), $received->getQuantity(), $received->getRecordedBy()]);
        self::assertSame([[$this->laptop->getId()->toRfc4122(), $this->site->getId()->toRfc4122(), '7.000']], $this->levels());
    }

    public function testEachMovementIsSaidAsAChangeToTheProductsStockInsideItsTransaction(): void
    {
        $this->track(SettingAddress::company($this->company), true);
        $actor = Uuid::v7();

        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '5', $actor);
        $this->keep->count($this->company, $this->laptop->getId(), $this->site->getId(), '4', $actor);

        self::assertSame(
            [['stock', $this->laptop->getId()->toRfc4122(), 'stock.received', $actor->toRfc4122(), $this->company->getId()->toRfc4122()], ['stock', $this->laptop->getId()->toRfc4122(), 'stock.counted', $actor->toRfc4122(), $this->company->getId()->toRfc4122()]],
            array_map(static fn ($change) => [$change->kind, $change->id?->toRfc4122(), $change->action, $change->actorUserId?->toRfc4122(), $change->companyId?->toRfc4122()], $this->liveChanges->staged),
        );
    }

    public function testWhatIsNotTrackedOrNotTheCompanysIsRefusedNamingTheField(): void
    {
        $this->track(SettingAddress::company($this->company), true);
        $this->track(SettingAddress::product($this->company, $this->laptop->getId()), false);
        $globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $theirs = StockLocation::defaultOf(Establishment::create($globex, '000', 'Globex', true, $this->clock->now()), $this->clock->now());

        foreach ([
            'productId' => [$this->laptop->getId(), $this->site->getId()],
            'productId ' => [Uuid::v7(), $this->site->getId()],
            'productId  ' => [$this->support->getId(), $this->site->getId()],
            'locationId' => [$this->cable->getId(), Uuid::v7()],
            'locationId ' => [$this->cable->getId(), $theirs->getId()],
        ] as $field => [$productId, $locationId]) {
            try {
                $this->keep->receive($this->company, $productId, $locationId, '1', null);
                self::fail('The receipt was accepted.');
            } catch (InvalidStockMovement $refused) {
                self::assertSame(trim($field), $refused->field);
            }
        }
        self::assertSame([], $this->movements->movements);
    }

    public function testACountRecordsTheDifferenceFromTheStockItFoundInOneTransaction(): void
    {
        $this->track(SettingAddress::company($this->company), true);
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '5', null);

        $short = $this->keep->count($this->company, $this->laptop->getId(), $this->site->getId(), '3', null);
        $same = $this->keep->count($this->company, $this->laptop->getId(), $this->site->getId(), '3', null);

        self::assertSame([StockMovementKind::Adjustment, '-2.000', '0.000'], [$short->getKind(), $short->getQuantity(), $same->getQuantity()]);
        self::assertSame(3, $this->transactions->committed, 'the receipt and both counts');
        $at = $this->laptop->getId()->toRfc4122().' '.$this->site->getId()->toRfc4122().' in transaction';
        self::assertSame(['lock '.$at, 'onHand '.$at, 'lock '.$at, 'onHand '.$at], $this->movements->calls, 'a count reads its stock under the lock another count or a delivery takes');
        self::assertSame([[$this->laptop->getId()->toRfc4122(), $this->site->getId()->toRfc4122(), '3.000']], $this->levels());
        $this->expectException(InvalidStockMovement::class);
        $this->keep->count($this->company, $this->laptop->getId(), $this->site->getId(), '-1', null);
    }

    public function testAProductsMovementsAreListedNewestFirstAPageAtATime(): void
    {
        $this->track(SettingAddress::company($this->company), true);
        $received = $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '5', null);
        $this->clock->modify('+1 hour');
        $counted = $this->keep->count($this->company, $this->laptop->getId(), $this->site->getId(), '4', null);

        $this->clock->modify('+1 hour');
        $cable = $this->keep->receive($this->company, $this->cable->getId(), $this->site->getId(), '2', null);

        $ofLaptop = new StockMovementSearch($this->laptop->getId());
        self::assertSame([$counted, $received], $this->page($ofLaptop)->items);
        self::assertSame([$cable, $counted, $received], $this->page(new StockMovementSearch())->items, "without a product, the company's own");
        self::assertSame([], $this->page(new StockMovementSearch($this->support->getId()))->items);

        // A page says how many there are in all, so a screen showing two of three says three rather than two.
        $first = $this->page($ofLaptop, new PageRequest(1, 1));
        self::assertSame([[$counted], 2], [$first->items, $first->total]);
        $second = $this->page($ofLaptop, new PageRequest(2, 1));
        self::assertSame([[$received], 2], [$second->items, $second->total]);

        $other = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        self::assertSame([], $this->keep->searchMovements($other, $ofLaptop, new PageRequest(1, 25))->items);
    }

    /** @return Page<StockMovement> */
    private function page(StockMovementSearch $search, ?PageRequest $request = null): Page
    {
        return $this->keep->searchMovements($this->company, $search, $request ?? new PageRequest(1, 25));
    }

    private function track(SettingAddress $address, bool $on): void
    {
        $existing = $this->settings->find($address, self::TRACKING);
        if (null !== $existing) {
            $this->settings->remove($existing);
        }
        $this->settings->save(new Setting($address, self::TRACKING, $on, $this->clock->now()));
    }

    /** A rack under the site, in the same establishment: where a move puts the goods. */
    private function rack(): StockLocation
    {
        $rack = StockLocation::create($this->site->getEstablishment(), $this->site, StockLocationKind::Rack, 'R1', 'Rack 1', $this->clock->now());
        $this->locations->save($rack);

        return $rack;
    }

    /** @return list<array{string, string, string}> */
    private function levels(): array
    {
        return array_map(static fn (StockLevel $level) => [$level->productId->toRfc4122(), $level->locationId->toRfc4122(), $level->quantity], $this->keep->levels($this->company));
    }

    public function testAMoveTakesGoodsFromOneLocationToAnotherInOneTransaction(): void
    {
        $this->track(SettingAddress::company($this->company), true);
        $actor = Uuid::v7();
        $rack = $this->rack();
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '10', $actor);

        [$out, $in] = $this->keep->move($this->company, $this->laptop->getId(), $this->site->getId(), $rack->getId(), '4', $actor);

        self::assertSame(['-4.000', '4.000'], [$out->getQuantity(), $in->getQuantity()]);
        self::assertSame(
            [[$this->laptop->getId()->toRfc4122(), $this->site->getId()->toRfc4122(), '6.000'], [$this->laptop->getId()->toRfc4122(), $rack->getId()->toRfc4122(), '4.000']],
            $this->levels(),
            'the stock left one location and arrived at the other, and the total did not change',
        );
        // One transaction for the pair: half a move is stock invented or lost.
        self::assertSame(2, $this->transactions->committed);
    }

    public function testAMoveIsRefusedForMoreThanIsAtTheLocationItLeaves(): void
    {
        $this->track(SettingAddress::company($this->company), true);
        $rack = $this->rack();
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '3', null);

        try {
            $this->keep->move($this->company, $this->laptop->getId(), $this->site->getId(), $rack->getId(), '4', null);
            self::fail('Stock that is not there was moved.');
        } catch (InvalidStockMovement $refused) {
            self::assertSame('quantity', $refused->field);
        }
        self::assertSame([[$this->laptop->getId()->toRfc4122(), $this->site->getId()->toRfc4122(), '3.000']], $this->levels());
    }

    public function testAMoveIsSaidAsOneChangeToTheProductsStock(): void
    {
        $this->track(SettingAddress::company($this->company), true);
        $actor = Uuid::v7();
        $rack = $this->rack();
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '5', $actor);

        $this->keep->move($this->company, $this->laptop->getId(), $this->site->getId(), $rack->getId(), '2', $actor);

        // The receipt's change is kept in the list on purpose: clearing it would need an assignment to the recorder's
        // own array, and what this asserts is that the move added ONE entry to whatever was there, not two.
        $product = $this->laptop->getId()->toRfc4122();
        self::assertSame(
            [['stock', $product, 'stock.received'], ['stock', $product, 'stock.moved']],
            array_map(static fn (LiveChange $change) => [$change->kind, $change->id?->toRfc4122(), $change->action], $this->liveChanges->staged),
            'one move is one change, not two',
        );
    }

    public function testAReceiptOpensALotUnderItsCodeAndStockIsKeptPerLot(): void
    {
        $this->track(SettingAddress::company($this->company), true);
        $this->laptop->track(ProductTracking::Lot, $this->clock->now());
        $rack = $this->rack();

        $first = $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '5', null, new NamedLot(' L1 ', new \DateTimeImmutable('2027-01-31')));
        $again = $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '2', null, new NamedLot('L1'));
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '4', null, new NamedLot('L2'));

        self::assertNotNull($first->getLot());
        self::assertSame($first->getLot(), $again->getLot(), 'a code seen before is the same lot');
        self::assertSame(['L1', 'L2'], array_map(static fn (StockLot $lot) => $lot->getCode(), $this->lots->lots));
        self::assertSame('2027-01-31', $first->getLot()->getExpiresOn()?->format('Y-m-d'));

        $counted = $this->keep->count($this->company, $this->laptop->getId(), $this->site->getId(), '6', null, new NamedLot('L1'));
        self::assertSame('-1.000', $counted->getQuantity(), 'a count compares with the stock of ITS lot');
        try {
            $this->keep->move($this->company, $this->laptop->getId(), $this->site->getId(), $rack->getId(), '5', null, new NamedLot('L2'));
            self::fail('More of a lot than it holds there was moved.');
        } catch (InvalidStockMovement $refused) {
            self::assertSame('quantity', $refused->field);
        }
        [, $in] = $this->keep->move($this->company, $this->laptop->getId(), $this->site->getId(), $rack->getId(), '4', null, new NamedLot('L2'));
        self::assertSame('L2', $in->getLot()?->getCode());

        $levels = array_map(static fn (StockLevel $level) => [$level->locationId->toRfc4122(), $level->lotCode, $level->lotExpiresOn, $level->quantity], $this->keep->levels($this->company));
        sort($levels);
        $site = $this->site->getId()->toRfc4122();
        $expected = [[$rack->getId()->toRfc4122(), 'L2', null, '4.000'], [$site, 'L1', '2027-01-31', '6.000'], [$site, 'L2', null, '0.000']];
        sort($expected);
        self::assertSame($expected, $levels);
    }

    public function testALotIsRequiredOfATrackedProductRefusedOfAnUntrackedOneAndNeverInventedByAMove(): void
    {
        $this->track(SettingAddress::company($this->company), true);
        $this->laptop->track(ProductTracking::Lot, $this->clock->now());
        $rack = $this->rack();

        foreach ([
            'lot' => fn () => $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '1', null),
            'lot ' => fn () => $this->keep->receive($this->company, $this->cable->getId(), $this->site->getId(), '1', null, new NamedLot('L1')),
            'lotCode' => fn () => $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '1', null, new NamedLot('L 1')),
            'lotCode ' => fn () => $this->keep->move($this->company, $this->laptop->getId(), $this->site->getId(), $rack->getId(), '1', null, new NamedLot('NEVER-SEEN')),
        ] as $field => $refusedCall) {
            try {
                $refusedCall();
                self::fail(\sprintf('Accepted where %s is refused.', trim($field)));
            } catch (InvalidStockMovement $refused) {
                self::assertSame(trim($field), $refused->field);
            }
        }
        self::assertSame([], $this->movements->movements);
        self::assertSame([], $this->lots->lots, 'a refused movement opens no lot');
    }

    public function testASerialNumberIsInStockOnceAcrossTheCompany(): void
    {
        $this->track(SettingAddress::company($this->company), true);
        $this->laptop->track(ProductTracking::Serial, $this->clock->now());
        $rack = $this->rack();
        $serial = new NamedLot('SN-0001');

        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '1', null, $serial);
        foreach ([
            'received again' => fn () => $this->keep->receive($this->company, $this->laptop->getId(), $rack->getId(), '1', null, $serial),
            'counted elsewhere' => fn () => $this->keep->count($this->company, $this->laptop->getId(), $rack->getId(), '1', null, $serial),
        ] as $case => $refusedCall) {
            try {
                $refusedCall();
                self::fail(\sprintf('A serial number in stock was %s.', $case));
            } catch (InvalidStockMovement $refused) {
                self::assertSame('lot', $refused->field, $case);
                self::assertStringContainsString('SN-0001', $refused->getMessage());
            }
        }
        self::assertSame(['lock-lot '.$this->laptop->getId()->toRfc4122().' SN-0001 in transaction'], array_values(array_unique($this->lots->calls)));

        $this->keep->count($this->company, $this->laptop->getId(), $this->site->getId(), '0', null, $serial);
        $this->keep->receive($this->company, $this->laptop->getId(), $rack->getId(), '1', null, $serial);
        self::assertSame('1.000', $this->movements->onHandOfLot($this->lots->lots[0]->getId()), 'gone, then back: once again');
    }
}

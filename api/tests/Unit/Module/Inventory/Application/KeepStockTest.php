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
use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockMovementKind;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductCategory;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryProducts;
use App\Tests\Support\InMemorySettings;
use App\Tests\Support\InMemoryStockLocations;
use App\Tests\Support\InMemoryStockMovements;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class KeepStockTest extends TestCase
{
    private const string TRACKING = 'article.stock_tracking';

    private MockClock $clock;
    private InMemoryStockMovements $movements;
    private InMemoryProducts $products;
    private InMemorySettings $settings;
    private FakeTransactions $transactions;
    private KeepStock $keep;
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
        $locations = new InMemoryStockLocations();
        $establishments = new InMemoryEstablishments();
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $main = Establishment::create($this->company, '000', 'Siège', true, $now);
        $establishments->save($main);
        $this->site = new ManageStockLocations($locations, $this->movements, $establishments, new InMemoryAuditTrail(), $this->clock)->defaultOf($main);
        $piece = Unit::create($this->company, 'C62', 'Pièce', 0, 1, $now);
        $this->accessories = ProductCategory::create($this->company, 'Accessoires', null, $now);
        $this->laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '1250'), $piece, null, [], $now);
        $this->cable = Product::create($this->company, 'ART-002', new ProductDetails('Câble', null, ProductKind::Goods, '9'), $piece, $this->accessories, [], $now);
        $this->support = Product::create($this->company, 'SRV-001', new ProductDetails('Assistance', null, ProductKind::Service, '50'), $piece, null, [], $now);
        foreach ([$this->laptop, $this->cable, $this->support] as $product) {
            $this->products->save($product);
        }
        $read = new ReadSetting(new ResolveSettings(new SettingCatalog([new BusinessDefaultSettings()]), $this->settings));
        $this->keep = new KeepStock($this->movements, $locations, $this->products, $read, $this->transactions, $this->clock);
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
        self::assertSame(2, $this->transactions->committed);
        self::assertSame([[$this->laptop->getId()->toRfc4122(), $this->site->getId()->toRfc4122(), '3.000']], $this->levels());
        $this->expectException(InvalidStockMovement::class);
        $this->keep->count($this->company, $this->laptop->getId(), $this->site->getId(), '-1', null);
    }

    public function testAProductsMovementsAreListedNewestFirst(): void
    {
        $this->track(SettingAddress::company($this->company), true);
        $received = $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '5', null);
        $this->clock->modify('+1 hour');
        $counted = $this->keep->count($this->company, $this->laptop->getId(), $this->site->getId(), '4', null);

        $this->clock->modify('+1 hour');
        $cable = $this->keep->receive($this->company, $this->cable->getId(), $this->site->getId(), '2', null);

        self::assertSame([$counted, $received], $this->keep->movementsOf($this->company, $this->laptop->getId()));
        self::assertSame([$cable, $counted, $received], $this->keep->movementsOf($this->company, null), 'without a product, the company\'s latest');
        self::assertSame([], $this->keep->movementsOf($this->company, $this->support->getId()));
        self::assertSame([], $this->keep->movementsOf(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), $this->laptop->getId()));
    }

    private function track(SettingAddress $address, bool $on): void
    {
        $existing = $this->settings->find($address, self::TRACKING);
        if (null !== $existing) {
            $this->settings->remove($existing);
        }
        $this->settings->save(new Setting($address, self::TRACKING, $on, $this->clock->now()));
    }

    /** @return list<array{string, string, string}> */
    private function levels(): array
    {
        return array_map(static fn (StockLevel $level) => [$level->productId->toRfc4122(), $level->locationId->toRfc4122(), $level->quantity], $this->keep->levels($this->company));
    }
}

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
use App\Module\Inventory\Application\ReadReceiptCost;
use App\Module\Inventory\Application\StockCostSettings;
use App\Module\Inventory\Domain\CostOnReceive;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Products\Domain\Product;
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
use App\Tests\Support\InMemoryStockLots;
use App\Tests\Support\InMemoryStockMovements;
use App\Tests\Support\RecordingLiveChanges;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ReadReceiptCostTest extends TestCase
{
    private InMemorySettings $settings;
    private InMemoryStockMovements $movements;
    private KeepStock $keep;
    private ReadReceiptCost $read;
    private MockClock $clock;
    private Company $company;
    private StockLocation $site;
    private Product $laptop;

    public function testAProductWithNothingReceivedOffersItsOwnCostAsTheCostNowAndTheAverageAndNoLastPrice(): void
    {
        $cost = $this->read->read($this->company, $this->laptop, null, null);

        self::assertSame([CostOnReceive::Suggest, '1000.0000', '1000.0000', null, null], [$cost->mode, $cost->costNow, $cost->average, $cost->lastCost, $cost->lastAt]);
    }

    public function testTheAverageOfferedIsTheOneTheReceiptWouldLeaveAndTheLastPriceIsTheLatestCostSomebodyTyped(): void
    {
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '10', null, null, '1200');
        $typedAt = $this->clock->now();
        $this->clock->modify('+1 hour');
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '5', null);

        $cost = $this->read->read($this->company, $this->laptop, '10', '1400');

        self::assertSame('1000.0000', $cost->costNow, 'nothing was applied');
        self::assertSame('1280.0000', $cost->average, '(15 x 1200 + 10 x 1400) over 25: the untyped receipt is valued at the average, and this one is included, as applying it would');
        self::assertSame('1200.0000', $cost->lastCost, 'the receipt typed with no cost is valued at the average, and nobody paid that');
        self::assertEquals($typedAt, $cost->lastAt);
    }

    public function testWithNoReceiptTypedTheAverageIsTheCurrentOneAndAnUnusableFigureIsLeftOut(): void
    {
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '10', null, null, '1200');

        foreach ([[null, null], ['10', null], [null, '1400'], ['abc', '1400'], ['10', 'abc'], ['10', '-5']] as [$quantity, $typed]) {
            self::assertSame('1200.0000', $this->read->read($this->company, $this->laptop, $quantity, $typed)->average, \sprintf('quantity %s, cost %s', $quantity ?? 'none', $typed ?? 'none'));
        }
    }

    public function testOnAStockSoldBelowNothingTheAverageOfferedIsTheReceiptsOwnCost(): void
    {
        $this->movements->save(StockMovement::sale($this->laptop, $this->site, '2', Uuid::v7(), $this->clock->now()));

        self::assertSame('1100.0000', $this->read->read($this->company, $this->laptop, '3', '1100')->average, 'not (3 x 1100 - 2 x 1000) over 1 = 1300');
    }

    public function testTheModeIsTheCompanysSetting(): void
    {
        $this->settings->save(new Setting(SettingAddress::company($this->company), StockCostSettings::COST_ON_RECEIVE, 'last', new \DateTimeImmutable()));

        self::assertSame(CostOnReceive::Last, $this->read->read($this->company, $this->laptop, null, null)->mode);
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-15 09:00:00');
        $now = $this->clock->now();
        $movements = $this->movements = new InMemoryStockMovements();
        $products = new InMemoryProducts();
        $this->settings = new InMemorySettings();
        $transactions = new FakeTransactions();
        $movements->transactions = $transactions;
        $lots = new InMemoryStockLots();
        $lots->transactions = $transactions;
        $locations = new InMemoryStockLocations();
        $establishments = new InMemoryEstablishments();
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $main = Establishment::create($this->company, '000', 'Siège', true, $now);
        $establishments->save($main);
        $this->site = new ManageStockLocations($locations, $movements, $establishments, new InMemoryAuditTrail($transactions), $this->clock, $transactions)->defaultOf($main);
        $piece = Unit::create($this->company, 'C62', 'Pièce', 0, 1, $now);
        $this->laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '1250', '1000'), $piece, null, [], $now);
        $products->save($this->laptop);
        $this->settings->save(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $read = new ReadSetting(new ResolveSettings(new SettingCatalog([new BusinessDefaultSettings(), new StockCostSettings()]), $this->settings));
        $this->keep = new KeepStock($movements, $lots, $locations, $products, $read, $transactions, $this->clock, new RecordingLiveChanges($transactions));
        $this->read = new ReadReceiptCost($movements, $read);
    }
}

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
use App\Module\Inventory\Application\StockCostSettings;
use App\Module\Inventory\Domain\CostBasis;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementCostKnown;
use App\Module\Products\Application\ChangeProductCost;
use App\Module\Products\Domain\CostChangeSource;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Infrastructure\Inventory\ProductCostOnReceipt;
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
use App\Tests\Support\InMemoryProductCostChanges;
use App\Tests\Support\InMemoryProducts;
use App\Tests\Support\InMemorySettings;
use App\Tests\Support\InMemoryStockLocations;
use App\Tests\Support\InMemoryStockLots;
use App\Tests\Support\InMemoryStockMovements;
use App\Tests\Support\RecordingLiveChanges;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class KeepStockCostOnReceiveTest extends TestCase
{
    private InMemorySettings $settings;
    private InMemoryStockMovements $movements;
    private InMemoryProducts $products;
    private InMemoryProductCostChanges $history;
    private KeepStock $keep;
    private Company $company;
    private StockLocation $site;
    private InMemoryStockLocations $locations;
    private Product $laptop;

    public function testInSuggestAReceiptKeepsTheCostUnlessThePersonAppliesTheLastPriceOrTheAverage(): void
    {
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '10', null, null, '1100');
        self::assertSame(['1000.0000', 0], [$this->laptop->getDetails()->costPrice, \count($this->history->changes)], 'asked nothing, applied nothing');

        $received = $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '10', null, null, '1300', CostBasis::Last);
        self::assertSame('1300.0000', $this->laptop->getDetails()->costPrice);
        $kept = $this->history->changes[0];
        self::assertSame(['1000.0000', '1300.0000', CostChangeSource::Receipt], [$kept->getOldCost(), $kept->getNewCost(), $kept->getSource()]);
        self::assertTrue($received->getId()->equals($kept->getSourceId()), 'the history names the receipt that moved the cost');

        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '20', null, null, '1000', CostBasis::Average);
        self::assertSame('1100.0000', $this->laptop->getDetails()->costPrice, '(10 x 1100 + 10 x 1300 + 20 x 1000) over 40, the receipt included');
    }

    public function testAutomaticLastAppliesTheTypedCostAndAReceiptTypedWithNoneChangesNothing(): void
    {
        $this->mode('manual');
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '10', null, null, '2000');
        $this->mode('last');

        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '5', null);
        self::assertSame(['1000.0000', 0], [$this->laptop->getDetails()->costPrice, \count($this->history->changes)], 'saved, that receipt is valued at the average of 2000: a figure nobody typed, so not one to apply');

        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '5', null, null, '1300');
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '5', null);
        self::assertSame(['1300.0000', 1], [$this->laptop->getDetails()->costPrice, \count($this->history->changes)]);
    }

    public function testAutomaticAverageAppliesTheWeightedAverageWithoutBeingAsked(): void
    {
        $this->mode('average');

        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '10', null, null, '1200');
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '10', null, null, '1400');

        self::assertSame('1300.0000', $this->laptop->getDetails()->costPrice);
        self::assertCount(2, $this->history->changes);
    }

    public function testManualNeverAppliesWhateverIsAskedAndAnAutomaticModeIgnoresWhatWasAsked(): void
    {
        $this->mode('manual');
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '10', null, null, '1400', CostBasis::Last);
        self::assertSame(['1000.0000', 0], [$this->laptop->getDetails()->costPrice, \count($this->history->changes)]);

        $this->mode('last');
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '10', null, null, '1500', CostBasis::Average);
        self::assertSame('1500.0000', $this->laptop->getDetails()->costPrice, 'the company set the rule, not the person at the counter');
    }

    public function testAReceiptLiftingAStockSoldBelowNothingIsAveragedAtItsOwnCost(): void
    {
        // Sold below nothing, the two units left at the cost price; the three that come in at 1 fill that hole, so what
        // is on the shelf is one unit worth 1, not (3 - 20) over 1 = -17, a cost no product takes (audit E-4).
        $this->mode('average');
        $nail = $this->product('ART-002', '10');
        $this->movements->save(StockMovement::sale($nail, $this->site, '2', Uuid::v7(), new \DateTimeImmutable('2026-09-15 08:00:00')));

        $this->keep->receive($this->company, $nail->getId(), $this->site->getId(), '3', null, null, '1');

        self::assertSame('1.0000', $nail->getDetails()->costPrice);
        self::assertSame('1.0000', $this->movements->averageCostOf($nail));
    }

    public function testAReceiptLiftingAStockBelowNothingForgetsWhatTheMissingUnitsWereWorth(): void
    {
        $this->mode('average');
        $vice = $this->product('ART-003', '100');
        $this->movements->save(StockMovement::sale($vice, $this->site, '5', Uuid::v7(), new \DateTimeImmutable('2026-09-15 08:00:00')));

        $this->keep->receive($this->company, $vice->getId(), $this->site->getId(), '10', null, null, '60');

        self::assertSame('60.0000', $vice->getDetails()->costPrice, 'the five on the shelf came in at 60, not (600 - 500) over 5 = 20');
    }

    public function testACostEnteredLaterLiftsAStockBelowNothingAsTheReceiptWouldHaveAtThatCost(): void
    {
        // Five sold below nothing at the cost price of 100; ten come in « à compléter », valued at that 100 meanwhile.
        $this->mode('average');
        $vice = $this->product('ART-003', '100');
        $this->movements->save(StockMovement::sale($vice, $this->site, '5', Uuid::v7(), new \DateTimeImmutable('2026-09-15 08:00:00')));
        $receipt = $this->keep->receive($this->company, $vice->getId(), $this->site->getId(), '10', null, costToComplete: true);
        self::assertTrue($receipt->isCostToComplete());
        self::assertSame('100.0000', $this->movements->averageCostOf($vice));

        $this->keep->enterCost($this->company, $receipt->getId(), '60', null, null);

        self::assertFalse($receipt->isCostToComplete());
        self::assertSame('60.0000', $this->movements->averageCostOf($vice), 'the five on the shelf came in at 60, as if typed on the receipt');
        self::assertSame('60.0000', $vice->getDetails()->costPrice);
        $this->expectException(StockMovementCostKnown::class);
        $this->keep->enterCost($this->company, $receipt->getId(), '70', null, null);
    }

    public function testAReceiptSplitOverSeveralPlacesMovesTheCostOnceFromTheWholeOfIt(): void
    {
        // One receipt put away on two shelves is one arrival of goods: the cost moves once, to the average with all of
        // it on the shelf, and the history keeps no figure from halfway through the put-away (audit 2026-10-06, E-12).
        $this->mode('average');
        $rack = StockLocation::create($this->site->getEstablishment(), $this->site, StockLocationKind::Rack, 'R1', 'Rack 1', new \DateTimeImmutable('2026-09-15 08:00:00'));
        $this->locations->save($rack);
        $this->keep->receive($this->company, $this->laptop->getId(), $this->site->getId(), '10', null, null, '1000');
        $before = \count($this->history->changes);

        $written = $this->keep->receiveSplit($this->company, $this->laptop->getId(), [
            ['locationId' => $this->site->getId(), 'quantity' => '5'],
            ['locationId' => $rack->getId(), 'quantity' => '5'],
        ], null, null, '1400');

        self::assertSame('1200.0000', $this->laptop->getDetails()->costPrice, '(10 x 1000 + 10 x 1400) over 20');
        self::assertCount($before + 1, $this->history->changes, 'one move of the cost for one receipt, not one per shelf');
        $moved = $this->history->changes[$before];
        self::assertSame(['1000.0000', '1200.0000'], [$moved->getOldCost(), $moved->getNewCost()]);
        self::assertTrue($written[1]->getId()->equals($moved->getSourceId()), 'the history names the part that completed the receipt');
    }

    protected function setUp(): void
    {
        $clock = new MockClock('2026-09-15 09:00:00');
        $now = $clock->now();
        $movements = $this->movements = new InMemoryStockMovements();
        $products = $this->products = new InMemoryProducts();
        $this->settings = new InMemorySettings();
        $transactions = new FakeTransactions();
        $movements->transactions = $transactions;
        $lots = new InMemoryStockLots();
        $lots->transactions = $transactions;
        $locations = $this->locations = new InMemoryStockLocations();
        $establishments = new InMemoryEstablishments();
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $main = Establishment::create($this->company, '000', 'Siège', true, $now);
        $establishments->save($main);
        $audit = new InMemoryAuditTrail($transactions);
        $this->site = new ManageStockLocations($locations, $movements, $establishments, $audit, $clock, $transactions)->defaultOf($main);
        $piece = Unit::create($this->company, 'C62', 'Pièce', 0, 1, $now);
        $this->laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '1250', '1000'), $piece, null, [], $now);
        $products->save($this->laptop);
        $this->settings->save(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $read = new ReadSetting(new ResolveSettings(new SettingCatalog([new BusinessDefaultSettings(), new StockCostSettings()]), $this->settings));
        $this->history = new InMemoryProductCostChanges();
        $this->keep = new KeepStock($movements, $lots, $locations, $products, $read, $transactions, $clock, new RecordingLiveChanges($transactions), null, new ProductCostOnReceipt(new ChangeProductCost($products, $this->history, $audit, $clock, $transactions)));
    }

    private function product(string $reference, string $costPrice): Product
    {
        $product = Product::create($this->company, $reference, new ProductDetails($reference, null, ProductKind::Goods, '20', $costPrice), $this->laptop->getUnit(), null, [], new \DateTimeImmutable('2026-09-15 07:00:00'));
        $this->products->save($product);

        return $product;
    }

    private function mode(string $mode): void
    {
        $this->settings->save(new Setting(SettingAddress::company($this->company), StockCostSettings::COST_ON_RECEIVE, $mode, new \DateTimeImmutable()));
    }
}

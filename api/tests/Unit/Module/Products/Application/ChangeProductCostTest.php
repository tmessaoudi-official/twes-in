<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Application;

use App\Fiscal\Domain\Model\Unit;
use App\Module\Products\Application\ChangeProductCost;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Application\PriceOnCostMove;
use App\Module\Products\Application\ProductPriceSettings;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\CostChangeSource;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEffects;
use App\Tests\Support\InMemoryProductCostChanges;
use App\Tests\Support\InMemoryProducts;
use App\Tests\Support\InMemorySettings;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ChangeProductCostTest extends TestCase
{
    private InMemoryProductCostChanges $history;
    private InMemoryAuditTrail $audit;
    private InMemoryProducts $products;
    private InMemorySettings $settings;
    private InMemoryEffects $effects;
    private ChangeProductCost $change;
    private Company $company;
    private Unit $piece;

    public function testACostChangeIsKeptWithItsSourceAndWhoMadeItAndAuditedByFieldName(): void
    {
        $product = $this->product('10');
        $receipt = Uuid::v7();
        $actor = Uuid::v7();

        $changed = $this->change->handle($this->company, $product, '12.5', CostChangeSource::Receipt, $receipt, $actor);

        self::assertTrue($changed);
        self::assertSame('12.5000', $product->getDetails()->costPrice);
        self::assertCount(1, $this->history->changes);
        $kept = $this->history->changes[0];
        self::assertSame(['10.0000', '12.5000', CostChangeSource::Receipt], [$kept->getOldCost(), $kept->getNewCost(), $kept->getSource()]);
        self::assertTrue($receipt->equals($kept->getSourceId()));
        self::assertTrue($actor->equals($kept->getChangedBy()));
        $entry = $this->audit->entries[0];
        self::assertSame([ManageProducts::ENTITY_TYPE, ManageProducts::REVISED, ['fields' => ['costPrice']], $actor], [$entry->entityType, $entry->action, $entry->changes, $entry->actorUserId]);
    }

    public function testTheSameFigureWritesNeitherAHistoryRowNorAnAuditRow(): void
    {
        $product = $this->product('12.5');

        $changed = $this->change->handle($this->company, $product, '12.5000', CostChangeSource::Receipt, Uuid::v7(), null);

        self::assertFalse($changed, 'twelve and a half is twelve and a half, however many decimals say it');
        self::assertSame([[], []], [$this->history->changes, $this->audit->entries]);
    }

    public function testAProductWithNoCostGetsItsFirstOneFromNothing(): void
    {
        $product = $this->product(null);

        $this->change->handle($this->company, $product, '7', CostChangeSource::Receipt, Uuid::v7(), null);

        self::assertSame([null, '7.0000'], [$this->history->changes[0]->getOldCost(), $this->history->changes[0]->getNewCost()]);
    }

    public function testAReceiptMovingTheCostUnderTheKeptPriceOffersTheReviewByDefault(): void
    {
        $product = $this->product('12', '18.462');

        $this->change->handle($this->company, $product, '13', CostChangeSource::Receipt, Uuid::v7(), null);

        self::assertSame('18.4620', $product->getDetails()->unitPriceNet, 'the price is the company\'s to change');
        self::assertSame('12.0000', $product->priceReview()?->previousCost);
        self::assertSame(['fields' => ['costPrice']], $this->audit->entries[0]->changes);
    }

    public function testFollowingTheMarginSetsThePriceAtTheCurrencysDecimalsAndAuditsIt(): void
    {
        $this->choose('follow');
        $product = $this->product('12', '18.462');

        $this->change->handle($this->company, $product, '13', CostChangeSource::Receipt, Uuid::v7(), null);

        self::assertSame('20.0010', $product->getDetails()->unitPriceNet, '18.462 × 13 ÷ 12, to the dinar\'s three decimals');
        self::assertNull($product->priceReview());
        self::assertSame(['fields' => ['costPrice', 'unitPriceNet']], $this->audit->entries[0]->changes);
    }

    public function testAPriceThatFollowedItsCostIsSaidToWhoeverReceivedWithThePriceItHad(): void
    {
        $this->choose('follow');
        $product = $this->product('12', '18.462');

        $this->change->handle($this->company, $product, '13', CostChangeSource::Receipt, Uuid::v7(), null);

        self::assertSame([[PriceOnCostMove::PRICE_FOLLOWED, $product->getId()->toRfc4122(), ['previousPrice' => '18.4620']]], $this->effects->told(), 'a price moved by itself is never moved in silence, and « Annuler » needs the one it had');
    }

    public function testAMarginMovedUnderAKeptPriceIsSaidEachTimeAReceiptMovesTheCostAgain(): void
    {
        $product = $this->product('12', '18.462');

        $this->change->handle($this->company, $product, '13', CostChangeSource::Receipt, Uuid::v7(), null);
        $this->change->handle($this->company, $product, '13', CostChangeSource::Receipt, Uuid::v7(), null);
        $this->change->handle($this->company, $product, '14', CostChangeSource::Receipt, Uuid::v7(), null);

        $moved = [PriceOnCostMove::MARGIN_MOVED, $product->getId()->toRfc4122(), []];
        self::assertSame([$moved, $moved], $this->effects->told(), 'a receipt at the same cost moved nothing and says nothing, though the review is still open');
    }

    public function testAKeptPriceAndACostTypedInTheProductSayNothing(): void
    {
        $this->change->handle($this->company, $this->product('12', '18.462'), '13', CostChangeSource::Edited, null, null);
        $this->choose('keep');
        $this->change->handle($this->company, $this->product('12', '18.462'), '13', CostChangeSource::Receipt, Uuid::v7(), null);

        self::assertSame([], $this->effects->told());
    }

    public function testKeepingThePriceOpensNoReview(): void
    {
        $this->choose('keep');
        $product = $this->product('12', '18.462');

        $this->change->handle($this->company, $product, '13', CostChangeSource::Receipt, Uuid::v7(), null);

        self::assertSame('18.4620', $product->getDetails()->unitPriceNet);
        self::assertNull($product->priceReview());
    }

    public function testACostTypedInTheProductIsSeenBesideItsPriceAndOpensNoReview(): void
    {
        $product = $this->product('12', '18.462');

        $this->change->handle($this->company, $product, '13', CostChangeSource::Edited, null, null);

        self::assertNull($product->priceReview());
    }

    protected function setUp(): void
    {
        $transactions = new FakeTransactions();
        $this->history = new InMemoryProductCostChanges();
        $this->audit = new InMemoryAuditTrail($transactions);
        $this->products = new InMemoryProducts();
        $this->settings = new InMemorySettings();
        $this->effects = new InMemoryEffects();
        $read = new ReadSetting(new ResolveSettings(new SettingCatalog([new ProductPriceSettings()]), $this->settings));
        $this->change = new ChangeProductCost($this->products, $this->history, $this->audit, new MockClock('2026-10-03 10:00:00'), $transactions, new PriceOnCostMove($read, ShippedFiscalPresets::scales(), $this->effects));
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->piece = Unit::create($this->company, 'C62', 'Pièce', 0, 1, new \DateTimeImmutable());
    }

    private function choose(string $mode): void
    {
        $this->settings->save(new Setting(SettingAddress::company($this->company), ProductPriceSettings::ON_COST_CHANGE, $mode, new \DateTimeImmutable()));
    }

    private function product(?string $cost, string $price = '1250'): Product
    {
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, $price, $cost), $this->piece, null, [], new \DateTimeImmutable());
        $this->products->save($product);

        return $product;
    }
}

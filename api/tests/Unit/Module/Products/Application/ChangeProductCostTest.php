<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Application;

use App\Fiscal\Domain\Unit;
use App\Module\Products\Application\ChangeProductCost;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Domain\CostChangeSource;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryProductCostChanges;
use App\Tests\Support\InMemoryProducts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ChangeProductCostTest extends TestCase
{
    private InMemoryProductCostChanges $history;
    private InMemoryAuditTrail $audit;
    private InMemoryProducts $products;
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

    protected function setUp(): void
    {
        $transactions = new FakeTransactions();
        $this->history = new InMemoryProductCostChanges();
        $this->audit = new InMemoryAuditTrail($transactions);
        $this->products = new InMemoryProducts();
        $this->change = new ChangeProductCost($this->products, $this->history, $this->audit, new MockClock('2026-10-03 10:00:00'), $transactions);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->piece = Unit::create($this->company, 'C62', 'Pièce', 0, 1, new \DateTimeImmutable());
    }

    private function product(?string $cost): Product
    {
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '1250', $cost), $this->piece, null, [], new \DateTimeImmutable());
        $this->products->save($product);

        return $product;
    }
}

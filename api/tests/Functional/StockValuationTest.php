<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Inventory\Application\StockCostSettings;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * What the stock is worth (docs/SPEC.md § 7): a receipt records what a unit cost, the stock is valued at the weighted
 * average of what came in, and whatever leaves, is counted away or moves leaves at that average.
 */
final class StockValuationTest extends ApiTestCase
{
    private Company $company;
    private string $laptopId;
    private string $mouseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '900'), $piece, null, [], $now);
        $mouse = Product::create($this->company, 'ART-002', new ProductDetails('Souris', null, ProductKind::Goods, '20', '8'), $piece, null, [], $now);
        $this->em()->persist($laptop);
        $this->em()->persist($mouse);
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        $this->laptopId = $laptop->getId()->toRfc4122();
        $this->mouseId = $mouse->getId()->toRfc4122();
        $this->createUser('keeper@twes.local', 'password-1234', $this->company, ['stock.read', 'stock.write', 'product.cost.read'], 'keeper');
        $this->createUser('blind@twes.local', 'password-1234', $this->company, ['stock.read', 'stock.write'], 'blind');
        $this->login('keeper@twes.local', 'password-1234');
    }

    public function testStockIsValuedAtTheWeightedAverageOfWhatCameIn(): void
    {
        $site = $this->site();
        $this->receive($this->laptopId, $site, '10', '500');
        $this->receive($this->laptopId, $site, '10', '700');

        $line = $this->line($this->laptopId);
        self::assertSame(['20.000', '600.0000', '12000.000'], [$line['quantity'], $line['unitCost'], $line['value']]);
        self::assertSame('12000.000', $this->valuation()['total']);
    }

    public function testWhatLeavesLeavesAtTheAverageSoTheAverageDoesNotMove(): void
    {
        $site = $this->site();
        $this->receive($this->laptopId, $site, '10', '500');
        $this->receive($this->laptopId, $site, '10', '700');

        $this->postJson($this->path('stock-movements'), ['operation' => 'count', 'productId' => $this->laptopId, 'locationId' => $site, 'quantity' => '15']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $line = $this->line($this->laptopId);
        self::assertSame(['15.000', '600.0000', '9000.000'], [$line['quantity'], $line['unitCost'], $line['value']], 'five pieces counted away at 600');
    }

    public function testAMoveBetweenLocationsLeavesTheValueAsItWas(): void
    {
        $site = $this->site();
        $this->receive($this->laptopId, $site, '10', '500');
        $this->postJson($this->path('stock-locations'), ['kind' => 'zone', 'code' => 'Z1', 'name' => 'Zone', 'parentId' => $site, 'establishmentId' => $this->establishmentId()]);
        $zone = $this->stringAt($this->json(), 'id');

        $this->postJson($this->path('stock-movements'), ['operation' => 'move', 'productId' => $this->laptopId, 'locationId' => $site, 'toLocationId' => $zone, 'quantity' => '4']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        self::assertSame(['10.000', '5000.000'], [$this->line($this->laptopId)['quantity'], $this->line($this->laptopId)['value']]);
    }

    public function testAReceiptWithNoCostIsValuedAtTheProductsOwnCostPrice(): void
    {
        $this->receive($this->mouseId, $this->site(), '10', null);

        $line = $this->line($this->mouseId);
        self::assertSame(['10.000', '8.0000', '80.000', '0.000'], [$line['quantity'], $line['unitCost'], $line['value'], $line['unvaluedQuantity']]);
    }

    public function testStockWithNoKnownCostIsCountedButNotValued(): void
    {
        $this->receive($this->laptopId, $this->site(), '3', null);

        $line = $this->line($this->laptopId);
        self::assertSame(['3.000', null, '0.000', '3.000'], [$line['quantity'], $line['unitCost'], $line['value'], $line['unvaluedQuantity']], 'the laptop has no cost price and the receipt no cost');
    }

    public function testAReceiptFillingAStockSoldBelowNothingIsAcceptedAndValuedAtItsOwnCost(): void
    {
        // The ruling's input (audit E-4): two sold at the cost price of 8 before any came in, then three received at
        // 1 under the average rule. Summed, (3 - 16) over 1 = -13 was handed to the product as its cost and refused.
        $this->em()->persist(new Setting(SettingAddress::company($this->company), StockCostSettings::COST_ON_RECEIVE, 'average', new \DateTimeImmutable()));
        $this->em()->flush();
        $site = $this->site();
        $this->sell($this->mouseId, $site, '2');

        $this->receive($this->mouseId, $site, '3', '1');

        $line = $this->line($this->mouseId);
        self::assertSame(['1.000', '1.0000', '1.000'], [$line['quantity'], $line['unitCost'], $line['value']]);
        $mouse = $this->em()->find(Product::class, Uuid::fromString($this->mouseId));
        self::assertSame('1.0000', $mouse?->getDetails()->costPrice);
    }

    public function testStockWithNoRecordedCostIsEstimatedAtTheProductsCostPriceAndSaysSo(): void
    {
        // C-02 (§ 7 2026-10-03 08:09): what has no recorded cost is valued at the product's cost price now, flagged.
        $site = $this->site();
        $this->receive($this->mouseId, $site, '10', '6');
        $mouse = $this->em()->find(Product::class, Uuid::fromString($this->mouseId));
        $location = $this->em()->find(StockLocation::class, Uuid::fromString($site));
        self::assertNotNull($mouse);
        self::assertNotNull($location);
        $this->em()->persist(StockMovement::receipt($mouse, $location, '5', null, new \DateTimeImmutable()));
        $this->em()->flush();
        // Written as a movement no cost was known for: nothing on it.
        $this->em()->getConnection()->executeStatement('UPDATE stock_movement SET unit_cost = NULL WHERE company_id = ? AND quantity = 5', [$this->company->getId()->toRfc4122()]);

        $line = $this->line($this->mouseId);
        self::assertSame(['15.000', '100.000', '0.000', '5.000', '6.6667'], [$line['quantity'], $line['value'], $line['unvaluedQuantity'], $line['estimatedQuantity'], $line['unitCost']], '10 at 6 and 5 estimated at 8');
        self::assertTrue($this->valuation()['estimated']);
    }

    public function testAValuationWithNothingEstimatedSaysSo(): void
    {
        $this->receive($this->mouseId, $this->site(), '10', '6');
        $this->receive($this->laptopId, $this->site(), '3', null);

        self::assertSame('0.000', $this->line($this->mouseId)['estimatedQuantity']);
        self::assertSame(['3.000', '0.000'], [$this->line($this->laptopId)['unvaluedQuantity'], $this->line($this->laptopId)['estimatedQuantity']], 'no cost price: nothing to estimate at');
        self::assertFalse($this->valuation()['estimated']);
    }

    public function testItNeedsTheRightToReadWhatThingsCost(): void
    {
        $this->login('blind@twes.local', 'password-1234');

        $this->getJson($this->path('stock-valuation'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testACostIsAnAmountOrNothing(): void
    {
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $this->site(), 'quantity' => '1', 'unitCost' => '-5']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('unitCost', (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function valuation(): array
    {
        $this->getJson($this->path('stock-valuation'));
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function line(string $productId): array
    {
        foreach ($this->arrayAt($this->valuation(), 'lines') as $line) {
            self::assertIsArray($line);
            if ($productId === ($line['productId'] ?? null)) {
                $named = [];
                foreach ($line as $key => $value) {
                    $named[(string) $key] = $value;
                }

                return $named;
            }
        }
        self::fail('The valuation has no line for the product.');
    }

    private function receive(string $productId, string $locationId, string $quantity, ?string $unitCost): void
    {
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $productId, 'locationId' => $locationId, 'quantity' => $quantity, 'unitCost' => $unitCost]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    private function sell(string $productId, string $locationId, string $quantity): void
    {
        $product = $this->em()->find(Product::class, Uuid::fromString($productId));
        $location = $this->em()->find(StockLocation::class, Uuid::fromString($locationId));
        self::assertNotNull($product);
        self::assertNotNull($location);
        static::getContainer()->get(StockMovementRepository::class)->save(StockMovement::sale($product, $location, $quantity, Uuid::v7(), new \DateTimeImmutable()));
    }

    private function site(): string
    {
        $this->getJson($this->path('stock-locations'));

        return $this->stringAt($this->jsonList()[0], 'id');
    }

    private function establishmentId(): string
    {
        $this->getJson($this->path('stock-locations'));

        return $this->stringAt($this->jsonList()[0], 'establishmentId');
    }

    private function path(string $resource): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource;
    }
}

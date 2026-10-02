<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

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

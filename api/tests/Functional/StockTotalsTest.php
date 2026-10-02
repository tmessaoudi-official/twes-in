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
use App\Tenancy\Domain\EstablishmentRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * What is on hand of each product asked for, all locations together: how a screen shows the stock of a product's
 * substitutes without reading every location.
 */
final class StockTotalsTest extends ApiTestCase
{
    private Company $company;
    private string $laptopId;
    private string $mouseId;
    private string $idleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $products = [];
        foreach ([['ART-001', 'Portable'], ['ART-002', 'Souris'], ['ART-003', 'Tapis']] as [$reference, $name]) {
            $product = Product::create($this->company, $reference, new ProductDetails($name, null, ProductKind::Goods, '10'), $piece, null, [], $now);
            $this->em()->persist($product);
            $products[] = $product;
        }
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        [$this->laptopId, $this->mouseId, $this->idleId] = array_map(static fn (Product $p): string => $p->getId()->toRfc4122(), $products);
        $this->createUser('keeper@twes.local', 'password-1234', $this->company, ['stock.read', 'stock.write'], 'keeper');
        $this->createUser('nobody@twes.local', 'password-1234', $this->company, ['customer.read'], 'nobody');
    }

    public function testTheStockOfAProductIsSummedOverEveryLocation(): void
    {
        $this->login('keeper@twes.local', 'password-1234');
        $site = $this->defaultLocationId();
        $this->postJson($this->path('stock-locations'), ['kind' => 'zone', 'code' => 'Z1', 'name' => 'Zone', 'parentId' => $site, 'establishmentId' => $this->establishmentId()]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $zone = $this->stringAt($this->json(), 'id');
        $this->receive($this->laptopId, $site, '10');
        $this->receive($this->laptopId, $zone, '4');
        $this->receive($this->mouseId, $site, '7');

        $this->getJson($this->path('stock-totals').'?productId[]='.$this->laptopId.'&productId[]='.$this->mouseId.'&productId[]='.$this->idleId);

        self::assertResponseIsSuccessful();
        $totals = [];
        foreach ($this->jsonList() as $row) {
            $totals[$this->stringAt($row, 'productId')] = $row['quantity'];
        }
        self::assertSame([$this->laptopId => '14.000', $this->mouseId => '7.000', $this->idleId => '0.000'], $totals, 'a product nothing moved for is on hand zero, not missing');
    }

    public function testItNeedsTheStockRightAndLeavesOutWhatIsNotAnId(): void
    {
        $this->login('nobody@twes.local', 'password-1234');
        $this->getJson($this->path('stock-totals').'?productId[]='.$this->laptopId);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->login('keeper@twes.local', 'password-1234');
        $this->getJson($this->path('stock-totals').'?productId[]=nope');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList(), 'a value that is no id names no product');
    }

    private function receive(string $productId, string $locationId, string $quantity): void
    {
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $productId, 'locationId' => $locationId, 'quantity' => $quantity]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    private function defaultLocationId(): string
    {
        $this->getJson($this->path('stock-locations'));

        return $this->stringAt($this->jsonList()[0], 'id');
    }

    private function establishmentId(): string
    {
        return static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0]->getId()->toRfc4122();
    }

    private function path(string $resource): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource;
    }
}

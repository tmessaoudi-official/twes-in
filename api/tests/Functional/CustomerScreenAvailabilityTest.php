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
 * What the customer screen may say of stock: in or out, a yes or a no, never a quantity, and only once the company
 * has turned that on (docs/SPEC.md § 7, 2026-10-03 08:20).
 */
final class CustomerScreenAvailabilityTest extends ApiTestCase
{
    private Company $company;
    private string $inStock;
    private string $outOfStock;
    private string $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $in = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '900'), $piece, null, [], $now);
        $out = Product::create($this->company, 'ART-002', new ProductDetails('Clavier', null, ProductKind::Goods, '30'), $piece, null, [], $now);
        $service = Product::create($this->company, 'SRV-001', new ProductDetails('Livraison', null, ProductKind::Service, '10'), $piece, null, [], $now);
        foreach ([$in, $out, $service] as $product) {
            $this->em()->persist($product);
        }
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        [$this->inStock, $this->outOfStock, $this->service] = array_map(static fn (Product $p): string => $p->getId()->toRfc4122(), [$in, $out, $service]);
        $this->createUser('keeper@twes.local', 'password-1234', $this->company, ['stock.read', 'stock.write', 'product.read'], 'keeper');
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, ['product.read'], 'clerk');
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['company.read'], 'reader');
        $this->login('keeper@twes.local', 'password-1234');
        $this->getJson($this->path('stock-locations'));
        $site = $this->stringAt($this->jsonList()[0], 'id');
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->inStock, 'locationId' => $site, 'quantity' => '10']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testNothingIsSaidOfStockUntilTheCompanyTurnsItOn(): void
    {
        $this->login('clerk@twes.local', 'password-1234');

        $this->getJson($this->availability([$this->inStock, $this->outOfStock]));

        self::assertResponseIsSuccessful();
        self::assertSame(['items' => []], $this->json());
    }

    public function testOnceOnItSaysYesOrNoForGoodsWhoseStockIsKeptAndNeverAQuantity(): void
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $this->em()->persist(new Setting(SettingAddress::company($company), 'customer_screen.show_stock', true, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->login('clerk@twes.local', 'password-1234');

        $this->getJson($this->availability([$this->inStock, $this->outOfStock, $this->service]));

        self::assertResponseIsSuccessful();
        $items = $this->arrayAt($this->json(), 'items');
        usort($items, static fn (mixed $a, mixed $b): int => \is_array($a) && \is_array($b) ? $a['productId'] <=> $b['productId'] : 0);
        $expected = [['productId' => $this->inStock, 'inStock' => true], ['productId' => $this->outOfStock, 'inStock' => false]];
        usort($expected, static fn (array $a, array $b): int => $a['productId'] <=> $b['productId']);
        self::assertSame($expected, $items, 'a service has no stock to speak of, and no row carries a quantity');
    }

    public function testSomebodyWhoCannotReadProductsAndAnotherCompanyAreAnsweredAsAStranger(): void
    {
        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->availability([$this->inStock]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->login('clerk@twes.local', 'password-1234');
        $other = $this->createCompany('Globex');
        $this->getJson('/api/companies/'.$other->getId()->toRfc4122().'/customer-screen/availability?ids[]='.$this->inStock);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param list<string> $ids */
    private function availability(array $ids): string
    {
        return $this->path('customer-screen/availability').'?'.implode('&', array_map(static fn (string $id): string => 'ids[]='.$id, $ids));
    }

    private function path(string $resource): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource;
    }
}

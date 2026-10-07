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
 * What a document's lines say of stock while they are typed: the quantity on hand at the document's establishment, for
 * the goods whose stock the company keeps, with the unit it is counted in (docs/SPEC.md § 7, the live line figures).
 */
final class StockOnHandTest extends ApiTestCase
{
    private Company $company;
    private string $laptop;
    private string $keyboard;
    private string $delivery;
    private string $piece;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $this->piece = $piece->getId()->toRfc4122();
        $now = new \DateTimeImmutable();
        $laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '900'), $piece, null, [], $now);
        $keyboard = Product::create($this->company, 'ART-002', new ProductDetails('Clavier', null, ProductKind::Goods, '30'), $piece, null, [], $now);
        $delivery = Product::create($this->company, 'SRV-001', new ProductDetails('Livraison', null, ProductKind::Service, '10'), $piece, null, [], $now);
        foreach ([$laptop, $keyboard, $delivery] as $product) {
            $this->em()->persist($product);
        }
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        [$this->laptop, $this->keyboard, $this->delivery] = array_map(static fn (Product $p): string => $p->getId()->toRfc4122(), [$laptop, $keyboard, $delivery]);
        $this->createUser('keeper@twes.local', 'password-1234', $this->company, ['company.read', 'stock.read', 'stock.write', 'product.read'], 'keeper');
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, ['company.read', 'invoice.read', 'invoice.write', 'product.read'], 'clerk');
        $this->login('keeper@twes.local', 'password-1234');
        $this->getJson($this->path('stock-locations'));
        $site = $this->stringAt($this->jsonList()[0], 'id');
        foreach (['5', '3'] as $quantity) {
            $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptop, 'locationId' => $site, 'quantity' => $quantity]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }
    }

    public function testItAnswersWhatIsOnHandAtTheEstablishmentForGoodsWhoseStockIsKeptInTheUnitItIsCounted(): void
    {
        $this->getJson($this->path('establishments'));
        $mainId = $this->stringAt($this->jsonList()[0], 'id');
        $this->createUser('owner@twes.local', 'password-1234', $this->company(), ['company.read', 'company.settings', 'stock.read', 'stock.write'], 'owner');
        $this->login('owner@twes.local', 'password-1234');
        $this->postJson($this->path('establishments'), ['code' => '001', 'name' => 'Agence de Sfax', 'city' => 'Sfax']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $sfaxId = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('stock-locations'), ['establishmentId' => $sfaxId, 'parentId' => null, 'kind' => 'zone', 'code' => 'SF1', 'name' => 'Réserve de Sfax']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->keyboard, 'locationId' => $this->stringAt($this->json(), 'id'), 'quantity' => '4']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        self::assertSame([$this->laptop => [$this->piece, '8.000'], $this->keyboard => [$this->piece, '0.000']], $this->onHandAt($mainId), 'a service has no stock, and the keyboards are in Sfax');
        self::assertSame([$this->laptop => [$this->piece, '0.000'], $this->keyboard => [$this->piece, '4.000']], $this->onHandAt($sfaxId));
        self::assertSame([$this->laptop => [$this->piece, '8.000'], $this->keyboard => [$this->piece, '0.000']], $this->onHandAt(null), 'a document that names no establishment is the main one\'s, never the whole company\'s');
    }

    public function testSomebodyWhoCannotReadStockAnotherCompanyAndItsEstablishmentAreAnsweredAsAStranger(): void
    {
        $this->login('clerk@twes.local', 'password-1234');
        $this->getJson($this->onHand([$this->laptop]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->login('keeper@twes.local', 'password-1234');
        $other = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($other);
        $this->getJson('/api/companies/'.$other->getId()->toRfc4122().'/stock-options/on-hand?ids[]='.$this->laptop);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $theirs = $this->em()->getConnection()->fetchOne('SELECT id FROM establishment WHERE company_id = ?', [$other->getId()->toRfc4122()]);
        self::assertIsString($theirs);
        $this->getJson($this->onHand([$this->laptop]).'&establishmentId='.$theirs);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherCompanysProductIsNotAnswered(): void
    {
        $other = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($other);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $other->getId());
        self::assertNotNull($unit);
        $theirs = Product::create($other, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '900'), $unit, null, [], new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->persist(new Setting(SettingAddress::company($other), 'article.stock_tracking', true, new \DateTimeImmutable()));
        $this->em()->flush();

        self::assertSame([$this->laptop => [$this->piece, '8.000']], $this->rows($this->onHand([$this->laptop, $theirs->getId()->toRfc4122()])));
    }

    /** @return array<string, array{string, string}> by product: its unit and what is on hand, in the order asked */
    private function onHandAt(?string $establishmentId): array
    {
        return $this->rows($this->onHand([$this->laptop, $this->keyboard, $this->delivery]).(null === $establishmentId ? '' : '&establishmentId='.$establishmentId));
    }

    /** @return array<string, array{string, string}> */
    private function rows(string $url): array
    {
        $this->getJson($url);
        self::assertResponseIsSuccessful();
        $answer = [];
        foreach ($this->arrayAt($this->json(), 'items') as $item) {
            self::assertIsArray($item);
            self::assertIsString($item['productId']);
            self::assertIsString($item['unitId']);
            self::assertIsString($item['onHand']);
            $answer[$item['productId']] = [$item['unitId'], $item['onHand']];
        }
        $order = [$this->laptop, $this->keyboard, $this->delivery];
        uksort($answer, static fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

        return $answer;
    }

    private function company(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $company;
    }

    /** @param list<string> $ids */
    private function onHand(array $ids): string
    {
        return $this->path('stock-options/on-hand').'?'.implode('&', array_map(static fn (string $id): string => 'ids[]='.$id, $ids));
    }

    private function path(string $resource): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource;
    }
}

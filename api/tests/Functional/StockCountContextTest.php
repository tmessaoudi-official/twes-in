<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Inventory\Application\KeepProductHomes;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Products\Domain\ValueObject\ProductTracking;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * What a count would change, read before it is recorded: what each place holds of the product, lot by lot, and where
 * the product lives, so the screen says « attendu ici 4, aussi 10 à Rayon B » and warns of a count at a place the
 * product is not kept, or of a lot it never had, before recording one.
 */
final class StockCountContextTest extends ApiTestCase
{
    private Company $company;
    private string $laptop;
    private string $drill;
    private string $delivery;
    private string $site;
    private string $rack;
    private string $bin;
    private string $shelf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '900'), $piece, null, [], $now);
        $drill = Product::create($this->company, 'ART-002', new ProductDetails('Perceuse', null, ProductKind::Goods, '90'), $piece, null, [], $now);
        $drill->track(ProductTracking::Lot, $now);
        $delivery = Product::create($this->company, 'SRV-001', new ProductDetails('Livraison', null, ProductKind::Service, '10'), $piece, null, [], $now);
        foreach ([$laptop, $drill, $delivery] as $product) {
            $this->em()->persist($product);
        }
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        [$this->laptop, $this->drill, $this->delivery] = array_map(static fn (Product $p): string => $p->getId()->toRfc4122(), [$laptop, $drill, $delivery]);

        $this->createUser('keeper@twes.local', 'password-1234', $this->company, ['company.read', 'stock.read', 'stock.write', 'product.read'], 'keeper');
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, ['company.read', 'stock.write', 'product.read'], 'clerk');
        $this->login('keeper@twes.local', 'password-1234');
        $this->getJson($this->path('stock-locations'));
        $this->site = $this->stringAt($this->jsonList()[0], 'id');
        $establishment = $this->stringAt($this->jsonList()[0], 'establishmentId');
        $this->rack = $this->place($establishment, $this->site, 'rack', 'R1', 'Rayon A');
        $this->bin = $this->place($establishment, $this->rack, 'bin', 'R1-1', 'Case A1');
        $this->shelf = $this->place($establishment, $this->site, 'rack', 'R2', 'Rayon B');

        $this->move(['operation' => 'receive', 'productId' => $this->laptop, 'locationId' => $this->rack, 'quantity' => '4']);
        $this->move(['operation' => 'receive', 'productId' => $this->laptop, 'locationId' => $this->shelf, 'quantity' => '10']);
        $this->move(['operation' => 'receive', 'productId' => $this->drill, 'locationId' => $this->shelf, 'quantity' => '3', 'lotCode' => 'L-1']);
        $this->move(['operation' => 'receive', 'productId' => $this->drill, 'locationId' => $this->shelf, 'quantity' => '2', 'lotCode' => 'L-2']);
        // A lot every piece of which has left still names itself: it is not a new lot to a later count.
        $this->move(['operation' => 'count', 'productId' => $this->drill, 'locationId' => $this->shelf, 'quantity' => '0', 'lotCode' => 'L-2']);

        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        static::getContainer()->get(KeepProductHomes::class)->set($company, Uuid::fromString($this->laptop), Uuid::fromString($this->rack), null);
    }

    public function testItAnswersWhatEachPlaceHoldsOfTheProductLotByLotAndWhereTheProductLives(): void
    {
        $items = $this->context([$this->laptop, $this->drill, $this->delivery]);

        self::assertSame([$this->laptop, $this->drill], array_keys($items), 'a service has no stock to count');
        self::assertSame([
            [$this->rack, null, '4.000'],
            [$this->shelf, null, '10.000'],
        ], $this->sorted($items[$this->laptop]['places']));
        self::assertSame([$this->rack], $items[$this->laptop]['homeLocationIds']);
        self::assertSame([
            [$this->shelf, 'L-1', '3.000'],
            [$this->shelf, 'L-2', '0.000'],
        ], $this->sorted($items[$this->drill]['places']), 'each lot at each place, an emptied one included');
        self::assertSame([], $items[$this->drill]['homeLocationIds']);
    }

    public function testAProductNothingMovedForAnswersNoPlace(): void
    {
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $new = Product::create($company, 'ART-003', new ProductDetails('Scie', null, ProductKind::Goods, '40'), $piece, null, [], new \DateTimeImmutable());
        $this->em()->persist($new);
        $this->em()->flush();

        $items = $this->context([$new->getId()->toRfc4122()]);

        self::assertSame(['places' => [], 'homeLocationIds' => []], $items[$new->getId()->toRfc4122()]);
    }

    public function testSomebodyWhoCannotReadStockAndAnotherCompanyAreAnsweredAsAStranger(): void
    {
        $this->login('clerk@twes.local', 'password-1234');
        $this->getJson($this->contextPath([$this->laptop]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->login('keeper@twes.local', 'password-1234');
        $other = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($other);
        $this->getJson('/api/companies/'.$other->getId()->toRfc4122().'/stock-counts/context?productIds[]='.$this->laptop);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherCompanysProductIsLeftOut(): void
    {
        $other = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($other);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $other->getId());
        self::assertNotNull($unit);
        $theirs = Product::create($other, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '900'), $unit, null, [], new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->persist(new Setting(SettingAddress::company($other), 'article.stock_tracking', true, new \DateTimeImmutable()));
        $this->em()->flush();

        self::assertSame([$this->laptop], array_keys($this->context([$this->laptop, $theirs->getId()->toRfc4122()])));
    }

    public function testItAnswersAtMostAsManyProductsAsASheetHolds(): void
    {
        $ids = array_map(static fn (): string => Uuid::v7()->toRfc4122(), range(1, 101));

        $this->getJson($this->contextPath($ids));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, array{places: list<array{locationId: string, lotCode: ?string, quantity: string}>, homeLocationIds: list<string>}>
     */
    private function context(array $ids): array
    {
        $this->getJson($this->contextPath($ids));
        self::assertResponseIsSuccessful();
        $answer = [];
        foreach ($this->arrayAt($this->json(), 'items') as $item) {
            self::assertIsArray($item);
            self::assertIsString($item['productId']);
            self::assertIsArray($item['places']);
            self::assertIsArray($item['homeLocationIds']);
            $places = [];
            foreach ($item['places'] as $place) {
                self::assertIsArray($place);
                self::assertIsString($place['locationId']);
                self::assertTrue(null === $place['lotCode'] || \is_string($place['lotCode']));
                self::assertIsString($place['quantity']);
                $places[] = ['locationId' => $place['locationId'], 'lotCode' => $place['lotCode'], 'quantity' => $place['quantity']];
            }
            $homes = array_values(array_filter($item['homeLocationIds'], 'is_string'));
            $answer[$item['productId']] = ['places' => $places, 'homeLocationIds' => $homes];
        }

        return $answer;
    }

    /**
     * @param list<array{locationId: string, lotCode: ?string, quantity: string}> $places
     *
     * @return list<array{string, ?string, string}>
     */
    private function sorted(array $places): array
    {
        $order = [$this->rack => 0, $this->bin => 1, $this->shelf => 2, $this->site => 3];
        $rows = array_map(static fn (array $place): array => [$place['locationId'], $place['lotCode'], $place['quantity']], $places);
        usort($rows, static fn (array $a, array $b): int => [$order[$a[0]] ?? 9, $a[1] ?? ''] <=> [$order[$b[0]] ?? 9, $b[1] ?? '']);

        return $rows;
    }

    /** @param list<string> $ids */
    private function contextPath(array $ids): string
    {
        return $this->path('stock-counts/context').'?'.implode('&', array_map(static fn (string $id): string => 'productIds[]='.$id, $ids));
    }

    /** @param array<string, string> $movement */
    private function move(array $movement): void
    {
        $this->postJson($this->path('stock-movements'), $movement);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    private function place(string $establishment, string $parent, string $kind, string $code, string $name): string
    {
        $this->postJson($this->path('stock-locations'), ['establishmentId' => $establishment, 'parentId' => $parent, 'kind' => $kind, 'code' => $code, 'name' => $name]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function path(string $resource): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource;
    }
}

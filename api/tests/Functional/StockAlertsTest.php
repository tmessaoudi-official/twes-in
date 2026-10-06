<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Alerts, not reports (docs/SPEC.md § 7): a count that found a difference, and stock that fell to its reorder point,
 * reach the people who keep the stock through the notification centre, as they happen.
 */
final class StockAlertsTest extends ApiTestCase
{
    private Company $company;
    private string $laptopId;
    private string $keeperId;
    private string $bossId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '900'), $piece, null, [], $now);
        $this->em()->persist($laptop);
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        $this->laptopId = $laptop->getId()->toRfc4122();
        $this->keeperId = $this->createUser('keeper@twes.local', 'password-1234', $this->company, ['stock.read', 'stock.write', 'product.read', 'product.write'], 'keeper')->getId()->toRfc4122();
        $this->bossId = $this->createUser('boss@twes.local', 'password-1234', $this->company, ['stock.read', 'stock.write'], 'boss')->getId()->toRfc4122();
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['stock.read'], 'reader');
        $this->login('keeper@twes.local', 'password-1234');
    }

    public function testACountThatFoundADifferenceTellsTheOtherKeepersAndOneThatFoundNoneTellsNobody(): void
    {
        $site = $this->site();
        $this->movement('receive', $site, '10');
        self::assertSame([], $this->told('stock.count_difference'));

        $this->movement('count', $site, '10');
        self::assertSame([], $this->told('stock.count_difference'), 'a count that found what was expected has nothing to say');

        $this->movement('count', $site, '7');

        $told = $this->told('stock.count_difference');
        self::assertSame([$this->bossId], $this->recipients($told), 'the person who counted knows; the other keepers are told, a reader is not');
        $raw = $told[0]['payload'];
        self::assertIsString($raw);
        $payload = json_decode($raw, true);
        self::assertIsArray($payload);
        self::assertSame(['ART-001', '-3.000', $this->laptopId], [$payload['reference'], $payload['difference'], $payload['product_id']]);
    }

    public function testStockThatFallsToItsReorderPointTellsAllTheKeepersOnceAndNotWhileItStaysThere(): void
    {
        $site = $this->site();
        $this->sendJson('PUT', $this->path('products/'.$this->laptopId.'/reorder-points/'.$this->establishmentId($site)), ['quantity' => '5']);
        self::assertResponseIsSuccessful();
        $this->movement('receive', $site, '10');
        self::assertSame([], $this->told('stock.low'), 'above the point');

        $this->movement('count', $site, '5');
        $first = $this->told('stock.low');
        self::assertEqualsCanonicalizing([$this->keeperId, $this->bossId], $this->recipients($first), 'falling to the point tells every keeper, the one who counted too');

        $this->movement('count', $site, '3');
        self::assertCount(2, $this->told('stock.low'), 'already at or under the point: no second alert');

        $this->movement('receive', $site, '10');
        $this->movement('count', $site, '4');
        self::assertCount(4, $this->told('stock.low'), 'back above and under again is a new fall');
    }

    public function testACountOverSeveralPlacesIsJudgedWholeSoGoodsFoundOnAnotherShelfAreNoFall(): void
    {
        // Five counted missing on the floor and found on the rack is stock that did not fall: the alert reads the count
        // as a whole, not the floor alone before the rack is written (audit 2026-10-06, N-e).
        $site = $this->site();
        $this->sendJson('PUT', $this->path('products/'.$this->laptopId.'/reorder-points/'.$this->establishmentId($site)), ['quantity' => '5']);
        self::assertResponseIsSuccessful();
        $floor = $this->em()->find(StockLocation::class, Uuid::fromString($site));
        self::assertNotNull($floor);
        $rack = StockLocation::create($floor->getEstablishment(), $floor, StockLocationKind::Rack, 'R1', 'Rack 1', new \DateTimeImmutable());
        $this->em()->persist($rack);
        $this->em()->flush();
        $rackId = $rack->getId()->toRfc4122();
        $this->movement('receive', $site, '8');
        $this->movement('receive', $rackId, '2');

        $this->postJson($this->path('stock-counts'), ['productId' => $this->laptopId, 'parts' => [
            ['locationId' => $site, 'quantity' => '3'],
            ['locationId' => $rackId, 'quantity' => '7'],
        ]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        self::assertSame([], $this->told('stock.low'), 'ten before, ten after: nothing fell');
        self::assertCount(2, $this->told('stock.count_difference'), 'each place that differed is still told, to the other keeper');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private function recipients(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            self::assertIsString($row['recipient_id']);
            $ids[] = $row['recipient_id'];
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    private function told(string $type): array
    {
        return $this->em()->getConnection()->fetchAllAssociative('SELECT recipient_id, payload FROM inbox_item WHERE type = :type ORDER BY recipient_id', ['type' => $type]);
    }

    private function movement(string $operation, string $locationId, string $quantity): void
    {
        $this->postJson($this->path('stock-movements'), ['operation' => $operation, 'productId' => $this->laptopId, 'locationId' => $locationId, 'quantity' => $quantity]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    private function site(): string
    {
        $this->getJson($this->path('stock-locations'));

        return $this->stringAt($this->jsonList()[0], 'id');
    }

    private function establishmentId(string $locationId): string
    {
        $this->getJson($this->path('stock-locations'));
        foreach ($this->jsonList() as $location) {
            if ($locationId === $location['id']) {
                return $this->stringAt($location, 'establishmentId');
            }
        }
        self::fail('The location is not listed.');
    }

    private function path(string $resource): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource;
    }
}

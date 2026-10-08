<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Where a product is, as the stock map lights it: each drawn place holding some, with what its own places under it
 * hold, and what lies where nothing is drawn, said rather than dropped, since the map is only as true as what it shows.
 */
final class StockMapSearchTest extends ApiTestCase
{
    private Company $company;
    private string $establishmentId;
    /** @var array<string, string> by code */
    private array $locationIds = [];
    private string $productId;
    /** The establishment's default place, whose code the provisioning chose. */
    private string $siteCode = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->establishmentId = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0]->getId()->toRfc4122();
        // Only goods whose stock is kept can be anywhere at all.
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, new \DateTimeImmutable()));
        $this->em()->flush();
    }

    public function testAProductIsFoundOnEveryFloorWhereItIsDrawnAndTheRestIsSaidUndrawn(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.read', 'product.write']);
        $this->place('R1', 'rack', null);
        $this->place('R1-A1', 'bin', 'R1');
        $this->place('R2', 'rack', null);
        $this->place('R3', 'rack', null);
        $this->place('RECEP', 'zone', null);
        $this->productId = $this->product();
        $ground = $this->floor('Rez-de-chaussée', 0);
        $upstairs = $this->floor('Étage 1', 1);
        // Drawn upstairs first, so the order read back is the floors', not the drawing's.
        $this->draw($upstairs, 'R2');
        $this->draw($ground, 'R1');
        $this->draw($ground, 'R3');

        $this->receive('R1', '3');
        $this->receive('R1-A1', '5');
        $this->receive('R2', '4');
        $this->receive('RECEP', '2');
        $this->receive('SITE', '1');

        $this->getJson($this->path('stock-whereabouts').'?productId[]='.$this->productId);
        self::assertResponseIsSuccessful();
        $asked = array_column($this->jsonList(), 'productId');
        self::assertSame(array_fill(0, \count($asked), $this->productId), $asked);
        self::assertSame([
            [$ground, $this->locationIds['R1'], 'R1', '8.000', [['R1', '3.000'], ['R1-A1', '5.000']]],
            [$upstairs, $this->locationIds['R2'], 'R2', '4.000', [['R2', '4.000']]],
            // R3 is drawn and holds none of it: it is not a place this product is.
            [null, null, null, '3.000', [[$this->siteCode, '1.000'], ['RECEP', '2.000']]],
        ], array_map(static fn (array $row): array => [
            $row['floorId'] ?? null,
            $row['locationId'] ?? null,
            $row['locationCode'] ?? null,
            $row['quantity'] ?? null,
            array_map(static fn (mixed $line): array => \is_array($line) ? [$line['locationCode'] ?? null, $line['quantity'] ?? null] : [], \is_array($row['lines'] ?? null) ? $row['lines'] : []),
        ], $this->jsonList()));

        // A product of no company of ours is nowhere, and a question naming none is answered with nothing.
        $this->getJson($this->path('stock-whereabouts').'?productId[]='.Uuid::v7()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList());
        $this->getJson($this->path('stock-whereabouts'));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList());

        // The map is read with the stock: somebody who reads only the catalogue reads neither.
        $this->signedIn(['product.read'], 'catalogue@twes.local', 'catalogue');
        $this->getJson($this->path('stock-whereabouts').'?productId[]='.$this->productId);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * What each drawn place on a floor holds, counted in products, so the board can say it on every shelf: a bin's goods
     * count for its rack, a product held in both counts once, and goods gone to nothing count for nothing.
     */
    public function testEachDrawnPlaceOfAFloorSaysHowManyProductsItHolds(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.read', 'product.write']);
        $this->place('R1', 'rack', null);
        $this->place('R1-A1', 'bin', 'R1');
        $this->place('R2', 'rack', null);
        $this->place('R3', 'rack', null);
        $this->place('R4', 'rack', null);
        $this->productId = $this->product();
        $washers = $this->product('RON-M6', 'Rondelle M6');
        $hinges = $this->product('CHA-35', 'Charnière 35');
        $ground = $this->floor('Rez-de-chaussée', 0);
        $upstairs = $this->floor('Étage 1', 1);
        $this->draw($ground, 'R1');
        $this->draw($ground, 'R3');
        $this->draw($ground, 'R4');
        $this->draw($upstairs, 'R2');

        $this->receive('R1', '3');
        $this->receive('R1-A1', '5');
        $this->receive('R1-A1', '2', $washers);
        $this->receive('R2', '4');
        $this->receive('R4', '6', $hinges);
        $this->postJson($this->path('stock-movements'), ['operation' => 'loss', 'productId' => $hinges, 'locationId' => $this->locationIds['R4'], 'quantity' => '6', 'reason' => 'broken', 'note' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->getJson($this->path('stock-floors', $ground).'/holdings');
        self::assertResponseIsSuccessful();
        // R3 holds nothing and R4 nothing any more: neither is listed. R2 is upstairs.
        self::assertSame(
            [[$this->locationIds['R1'], 2]],
            array_map(static fn (array $row): array => [$row['locationId'] ?? null, $row['products'] ?? null], $this->jsonList()),
        );

        $this->getJson($this->path('stock-floors', Uuid::v7()->toRfc4122()).'/holdings');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Read with the stock, as the map is.
        $this->signedIn(['product.read'], 'catalogue@twes.local', 'catalogue');
        $this->getJson($this->path('stock-floors', $ground).'/holdings');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** A delivery note's lines are found together, each row naming its product, in the order they were asked. */
    public function testSeveralProductsAreFoundInOneAnswerInTheOrderAsked(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.read', 'product.write']);
        $this->place('R1', 'rack', null);
        $this->place('R2', 'rack', null);
        $this->productId = $this->product();
        $washers = $this->product('RON-M6', 'Rondelle M6');
        $ground = $this->floor('Rez-de-chaussée', 0);
        $this->draw($ground, 'R1');
        $this->draw($ground, 'R2');
        $this->receive('R1', '3');
        $this->receive('R2', '40', $washers);
        $this->receive('R1', '10', $washers);

        // Asked twice, as a note naming one product on two lines asks: answered once, where it was first asked.
        $this->getJson($this->path('stock-whereabouts').'?productId[]='.$washers.'&productId[]='.$this->productId.'&productId[]='.$washers);
        self::assertResponseIsSuccessful();
        self::assertSame([
            [$washers, 'RON-M6', 'R1', '10.000'],
            [$washers, 'RON-M6', 'R2', '40.000'],
            [$this->productId, 'VIS-6X40', 'R1', '3.000'],
        ], array_map(static fn (array $row): array => [
            $row['productId'] ?? null, $row['productReference'] ?? null, $row['locationCode'] ?? null, $row['quantity'] ?? null,
        ], $this->jsonList()));
    }

    private function place(string $code, string $kind, ?string $parent): void
    {
        if ([] === $this->locationIds) {
            $this->getJson($this->path('stock-locations'));
            self::assertResponseIsSuccessful();
            $this->locationIds['SITE'] = $this->stringAt($this->jsonList()[0], 'id');
            $this->siteCode = $this->stringAt($this->jsonList()[0], 'code');
        }
        $this->postJson($this->path('stock-locations'), [
            'establishmentId' => $this->establishmentId,
            'parentId' => $this->locationIds[$parent ?? 'SITE'],
            'kind' => $kind,
            'code' => $code,
            'name' => $code,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->locationIds[$code] = $this->stringAt($this->json(), 'id');
    }

    private function product(string $reference = 'VIS-6X40', string $name = 'Vis 6x40'): string
    {
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $this->postJson($this->path('products'), [
            'reference' => $reference,
            'name' => $name,
            'description' => null,
            'kind' => 'goods',
            'unitId' => $piece->getId()->toRfc4122(),
            'unitPriceNet' => '0.45',
            'costPrice' => null,
            'categoryId' => null,
            'barcodes' => [],
            'defaultTaxComponentIds' => [],
            'customFields' => [],
            'isActive' => true,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function floor(string $name, int $level): string
    {
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => $name, 'level' => $level, 'widthMetres' => '24', 'depthMetres' => '15']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function draw(string $floorId, string $code): void
    {
        $this->postJson($this->path('stock-floors', $floorId).'/drawings', [
            'locationId' => $this->locationIds[$code], 'x' => '1', 'y' => '1', 'width' => '1.2', 'depth' => '4', 'rotation' => 0, 'height' => '2',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    private function receive(string $code, string $quantity, ?string $productId = null): void
    {
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $productId ?? $this->productId, 'locationId' => $this->locationIds[$code], 'quantity' => $quantity]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    private function path(string $resource, ?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource.(null === $id ? '' : '/'.$id);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions, string $email = 'map@twes.local', string $role = 'member'): void
    {
        // Re-found: the test client reboots the kernel between requests, so the company kept from the first one is
        // detached by the time a second sign-in needs it.
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? self::fail('the company vanished');
        $this->createUser($email, 'password-1234', $company, $permissions, $role);
        $this->login($email, 'password-1234');
        self::assertResponseIsSuccessful();
    }
}

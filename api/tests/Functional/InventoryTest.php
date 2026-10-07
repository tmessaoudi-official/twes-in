<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductCategory;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\ModuleRegistry\Domain\ModuleState;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class InventoryTest extends ApiTestCase
{
    private Company $company;
    private string $establishmentId;
    private string $laptopId;
    private string $supportId;
    private string $consumableId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->establishmentId = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0]->getId()->toRfc4122();
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable 14"', null, ProductKind::Goods, '1250'), $piece, null, [], $now);
        $support = Product::create($this->company, 'SRV-001', new ProductDetails('Assistance', null, ProductKind::Service, '50'), $piece, null, [], $now);
        // Goods the company keeps no stock of: the SETTING half of the rule, which no kind or column can stand in for.
        $consumable = Product::create($this->company, 'ART-009', new ProductDetails('Cartouche encre', null, ProductKind::Goods, '30'), $piece, null, [], $now);
        $this->em()->persist($laptop);
        $this->em()->persist($support);
        $this->em()->persist($consumable);
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->persist(new Setting(SettingAddress::product($this->company, $consumable->getId()), 'article.stock_tracking', false, $now));
        $this->em()->flush();
        $this->laptopId = $laptop->getId()->toRfc4122();
        $this->supportId = $support->getId()->toRfc4122();
        $this->consumableId = $consumable->getId()->toRfc4122();
    }

    public function testEachEstablishmentHasADefaultLocationAndAWriterArrangesItsTree(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);

        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();
        self::assertSame([[null, $this->establishmentId, 'site', '000', 'Acme', true, 0, 0]], $this->locationRows());
        $defaultId = $this->stringAt($this->jsonList()[0], 'id');

        $this->postJson($this->path('stock-locations'), $this->location(['kind' => 'zone', 'code' => 'Z1', 'name' => 'Zone froide']));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $zoneId = $this->stringAt($this->json(), 'id');
        self::assertSame([$defaultId, false], [$this->json()['parentId'], $this->json()['isDefault']]);
        $this->postJson($this->path('stock-locations'), $this->location(['parentId' => $zoneId, 'kind' => 'rack', 'code' => 'R1', 'name' => 'Rayonnage 1']));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $rackId = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path('stock-locations', $zoneId), $this->location(['kind' => 'building', 'code' => 'B1', 'name' => 'Bâtiment B']));
        self::assertResponseIsSuccessful();
        self::assertSame(['building', 'B1', 'Bâtiment B', $defaultId, 1], [$this->json()['kind'], $this->json()['code'], $this->json()['name'], $this->json()['parentId'], $this->json()['childCount']]);

        $this->postJson($this->path('stock-locations'), $this->location(['kind' => 'zone', 'code' => 'B1', 'name' => 'Doublon']));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $this->sendJson('PUT', $this->path('stock-locations', $zoneId), $this->location(['parentId' => $rackId, 'kind' => 'building', 'code' => 'B1', 'name' => 'Bâtiment B']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('parentId', (string) $this->client->getResponse()->getContent());
        $this->postJson($this->path('stock-locations'), $this->location(['kind' => 'cellar', 'code' => 'C1', 'name' => 'Cave']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->sendJson('DELETE', $this->path('stock-locations', $zoneId));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a location holding another is kept');
        $this->sendJson('DELETE', $this->path('stock-locations', $defaultId));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'the default location is kept');
        $this->sendJson('DELETE', $this->path('stock-locations', $rackId));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->sendJson('DELETE', $this->path('stock-locations', $rackId));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $actions = $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_type = 'stock_location'");
        self::assertEqualsCanonicalizing(['stock_location.created', 'stock_location.created', 'stock_location.revised', 'stock_location.deleted'], $actions);
    }

    public function testADeliveryIsSharedOutOverSeveralPlacesInOneRequestAndRefusedWholeWhenAnyPartIs(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $siteId = $this->defaultLocationId();
        $this->postJson($this->path('stock-locations'), $this->location(['kind' => 'zone', 'code' => 'Z1', 'name' => 'Zone froide']));
        $zoneId = $this->stringAt($this->json(), 'id');

        $this->postJson($this->path('stock-receipts'), ['productId' => $this->laptopId, 'parts' => [
            ['locationId' => $siteId, 'quantity' => '6'],
            ['locationId' => $zoneId, 'quantity' => '4'],
        ]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $parts = $this->json()['parts'];
        $movementIds = $this->json()['movementIds'];
        self::assertIsArray($parts);
        self::assertIsArray($movementIds);
        self::assertSame([$siteId, $zoneId], array_column($parts, 'locationId'));
        self::assertSame(['6.000', '4.000'], array_column($parts, 'quantity'));
        self::assertCount(2, $movementIds);
        self::assertEqualsCanonicalizing(
            [[$siteId, '6.000'], [$zoneId, '4.000']],
            array_map(static fn (array $row): array => [$row['locationId'], $row['quantity']], $this->levels()),
        );

        // One part the company does not have: the other part is not written either.
        $this->postJson($this->path('stock-receipts'), ['productId' => $this->laptopId, 'parts' => [
            ['locationId' => $siteId, 'quantity' => '5'],
            ['locationId' => Uuid::v7()->toRfc4122(), 'quantity' => '1'],
        ]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->postJson($this->path('stock-receipts'), ['productId' => $this->laptopId, 'parts' => [
            ['locationId' => $siteId, 'quantity' => '1'],
            ['locationId' => $siteId, 'quantity' => '2'],
        ]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->postJson($this->path('stock-receipts'), ['productId' => $this->laptopId, 'parts' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertEqualsCanonicalizing(
            [[$siteId, '6.000'], [$zoneId, '4.000']],
            array_map(static fn (array $row): array => [$row['locationId'], $row['quantity']], $this->levels()),
            'no refused receipt left anything behind',
        );
    }

    public function testAReceiptNamesItsVendorItsSupplierReferenceAndItsDayAndTheMovementsSayThem(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Sotumag'), new \DateTimeImmutable());
        $this->em()->persist($vendor);
        $this->em()->flush();
        $siteId = $this->defaultLocationId();
        $day = new \DateTimeImmutable('yesterday')->format('Y-m-d');
        $document = ['vendorId' => $vendor->getId()->toRfc4122(), 'supplierReference' => ' BL-77 ', 'receivedOn' => $day];

        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $siteId, 'quantity' => '3', ...$document]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([$vendor->getId()->toRfc4122(), 'Sotumag', 'BL-77', $day], [$this->json()['vendorId'], $this->json()['vendorName'], $this->json()['supplierReference'], $this->json()['receivedOn']]);

        $this->postJson($this->path('stock-receipts'), ['productId' => $this->laptopId, 'parts' => [['locationId' => $siteId, 'quantity' => '2']], ...$document]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $siteId, 'quantity' => '1']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([null, null, null, null], [$this->json()['vendorId'], $this->json()['vendorName'], $this->json()['supplierReference'], $this->json()['receivedOn']], 'a receipt that names none says none');

        $this->getJson($this->path('stock-movements').'?productId='.$this->laptopId);
        $rows = array_map(static fn (array $row): array => [$row['quantity'], $row['vendorName'], $row['supplierReference'], $row['receivedOn']], $this->jsonList());
        self::assertEqualsCanonicalizing([['3.000', 'Sotumag', 'BL-77', $day], ['2.000', 'Sotumag', 'BL-77', $day], ['1.000', null, null, null]], $rows);
    }

    public function testAReceiptRefusesAVendorThatIsNotTheCompanysADayToComeAndAnythingButAReceiptNamingOne(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $siteId = $this->defaultLocationId();
        $receive = ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $siteId, 'quantity' => '1'];

        foreach ([
            'vendorId' => ['vendorId' => Uuid::v7()->toRfc4122()],
            'receivedOn' => ['receivedOn' => new \DateTimeImmutable('tomorrow +1 day')->format('Y-m-d')],
            'supplierReference' => ['supplierReference' => str_repeat('x', 61)],
        ] as $field => $named) {
            $this->postJson($this->path('stock-movements'), [...$receive, ...$named]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
        }
        $this->postJson($this->path('stock-movements'), [...$receive, 'operation' => 'count', 'supplierReference' => 'BL-1']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'only a receipt carries a supplier reference');
        self::assertSame([], $this->levels(), 'nothing refused was written');
    }

    public function testNoVendorIsNamedOnAReceiptWhileVendorsIsSwitchedOff(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Sotumag'), new \DateTimeImmutable());
        $this->em()->persist($vendor);
        $this->em()->persist(ModuleState::of($this->company, 'vendors', false, new \DateTimeImmutable()));
        $this->em()->flush();

        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $this->defaultLocationId(), 'quantity' => '1', 'vendorId' => $vendor->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('vendorId', (string) $this->client->getResponse()->getContent());
    }

    public function testTheReceiveFormsVendorPickerFindsTheCompanysVendorsForAWriterWhileVendorsIsOn(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $sotumag = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Sotumag'), new \DateTimeImmutable());
        $other = Vendor::create($this->company, 'FRN-0002', new VendorProfile('Quincaillerie Ben Salah'), new \DateTimeImmutable());
        $this->em()->persist($sotumag);
        $this->em()->persist($other);
        $this->em()->flush();

        $this->getJson($this->path('stock-options/vendors').'?q=sotum');
        self::assertResponseIsSuccessful();
        self::assertSame([[$sotumag->getId()->toRfc4122(), 'FRN-0001', 'Sotumag']], array_map(static fn (array $row): array => [$row['id'], $row['number'], $row['name']], $this->jsonList()));

        $this->getJson($this->path('stock-options/vendors').'?ids[]='.$other->getId()->toRfc4122());
        self::assertSame(['Quincaillerie Ben Salah'], array_column($this->jsonList(), 'name'), 'the vendor a receipt names is read back by id');

        $this->em()->persist(ModuleState::of($this->company(), 'vendors', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->getJson($this->path('stock-options/vendors'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'no vendor book, nothing to pick from');
    }

    public function testTheVendorPickerIsForSomebodyWhoMayReceiveNotJustReadStock(): void
    {
        $this->signedIn(['stock.read']);

        $this->getJson($this->path('stock-options/vendors'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testASplitReceiptNeedsTheStockWritePermission(): void
    {
        $this->signedIn(['stock.read']);

        $this->postJson($this->path('stock-receipts'), ['productId' => $this->laptopId, 'parts' => [['locationId' => $this->defaultLocationId(), 'quantity' => '1']]]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testACountIsTakenOverSeveralPlacesInOneRequestEachWhatWasFoundThereAndRefusedWholeWhenAnyPartIs(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $siteId = $this->defaultLocationId();
        $this->postJson($this->path('stock-locations'), $this->location(['kind' => 'zone', 'code' => 'Z1', 'name' => 'Zone froide']));
        $zoneId = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $siteId, 'quantity' => '3']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson($this->path('stock-counts'), ['productId' => $this->laptopId, 'parts' => [
            ['locationId' => $siteId, 'quantity' => '5'],
            ['locationId' => $zoneId, 'quantity' => '2'],
        ]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $parts = $this->json()['parts'];
        self::assertIsArray($parts);
        self::assertSame([$siteId, $zoneId], array_column($parts, 'locationId'));
        self::assertSame(['5.000', '2.000'], array_column($parts, 'quantity'), 'each place says what was found there, not what moved');
        self::assertIsArray($this->json()['movementIds']);
        self::assertCount(2, $this->json()['movementIds']);
        self::assertEqualsCanonicalizing(
            [[$siteId, '5.000'], [$zoneId, '2.000']],
            array_map(static fn (array $row): array => [$row['locationId'], $row['quantity']], $this->levels()),
        );

        foreach ([
            'a place the company does not have' => [['locationId' => $siteId, 'quantity' => '9'], ['locationId' => Uuid::v7()->toRfc4122(), 'quantity' => '1']],
            'a place twice' => [['locationId' => $siteId, 'quantity' => '9'], ['locationId' => $siteId, 'quantity' => '1']],
            'no place' => [],
            'a count below nothing' => [['locationId' => $siteId, 'quantity' => '9'], ['locationId' => $zoneId, 'quantity' => '-1']],
        ] as $case => $refused) {
            $this->postJson($this->path('stock-counts'), ['productId' => $this->laptopId, 'parts' => $refused]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $case);
        }
        self::assertEqualsCanonicalizing(
            [[$siteId, '5.000'], [$zoneId, '2.000']],
            array_map(static fn (array $row): array => [$row['locationId'], $row['quantity']], $this->levels()),
            'no refused count left anything behind',
        );

        $this->createUser('reader@twes.local', 'password-1234', $this->company(), ['stock.read'], 'reader');
        $this->login('reader@twes.local', 'password-1234');
        $this->postJson($this->path('stock-counts'), ['productId' => $this->laptopId, 'parts' => [['locationId' => $siteId, 'quantity' => '1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'counting writes stock');
    }

    public function testTrackedGoodsAreReceivedAndCountedAndTheirStockListedByLocation(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $siteId = $this->defaultLocationId();

        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $siteId, 'quantity' => '10']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['in', '10.000', 'receipt', null], [$this->json()['kind'], $this->json()['quantity'], $this->json()['sourceType'], $this->json()['sourceId']]);
        $this->postJson($this->path('stock-movements'), ['operation' => 'count', 'productId' => $this->laptopId, 'locationId' => $siteId, 'quantity' => '8']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['adjustment', '-2.000', 'count'], [$this->json()['kind'], $this->json()['quantity'], $this->json()['sourceType']]);

        $this->getJson($this->path('stock-levels'));
        self::assertResponseIsSuccessful();
        $level = [
            'productId' => $this->laptopId,
            'productReference' => 'ART-001',
            'productName' => 'Portable 14"',
            'unitCode' => 'C62',
            'unitName' => 'Unité',
            'locationId' => $siteId,
            'locationCode' => '000',
            'locationName' => 'Acme',
            'establishmentId' => $this->establishmentId,
            'quantity' => '8.000',
            'id' => $this->laptopId.':'.$siteId,
        ];
        // Compared key by key, each side in the same order: a page also carries what Hydra puts on every member, and
        // in which order the serializer writes them is not what this is about.
        ksort($level);
        $shown = array_map(static function (array $row) use ($level): array {
            $row = array_intersect_key($row, $level);
            ksort($row);

            return $row;
        }, $this->jsonList());
        self::assertSame([$level], $shown);
        $this->getJson($this->path('stock-movements').'?productId='.$this->laptopId);
        self::assertSame(['adjustment', 'in'], array_column($this->jsonList(), 'kind'));
        // The movements table is the one that grows without end, so the API pages it and says how many there are
        // rather than answering a capped heap for the browser to cut up (docs/SPEC.md row 55 (b)).
        self::assertSame(2, $this->jsonPage()['totalItems']);
        // And the database is what pages it: a page of one answers one row while still saying there are two. Reading
        // the count off the rows would say one, and capping without a count would say two rows on every page.
        $this->getJson($this->path('stock-movements').'?productId='.$this->laptopId.'&itemsPerPage=1');
        self::assertSame(2, $this->jsonPage()['totalItems']);
        self::assertSame(['adjustment'], array_column($this->jsonList(), 'kind'), 'newest first, one to a page');
        // And every filter the screen offers is answered by the API, not by the page it sent: one that narrowed the
        // page alone would call a page of two the whole result, which is the untruth the cap above already was.
        foreach ([
            'q=Portable' => ['adjustment', 'in'],
            'q=nothing-here' => [],
            'kind=in' => ['in'],
            'sourceType=count' => ['adjustment'],
            'order[quantity]=asc' => ['adjustment', 'in'],
            'order[quantity]=desc' => ['in', 'adjustment'],
        ] as $query => $expected) {
            $this->getJson($this->path('stock-movements').'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($expected, array_column($this->jsonList(), 'kind'), $query);
            self::assertSame(\count($expected), $this->jsonPage()['totalItems'], $query);
        }
        // A filter naming one record is a claim that the record exists: something that is not an id is refused by the
        // parameter's own declaration, not ignored, or the answer is the whole list wearing the look of a filtered one.
        $this->getJson($this->path('stock-movements').'?productId=not-an-id');
        self::assertResponseStatusCodeSame(422);

        $this->getJson($this->path('stock-options'));
        self::assertResponseIsSuccessful();
        // The catalogue is not here: it is asked for a few at a time (testTheProductPickerOffersOnlyWhatStockIsKeptOf).
        self::assertArrayNotHasKey('products', $this->json());
        self::assertSame(['000'], array_column($this->arrayAt($this->json(), 'establishments'), 'code'));
        // The plan's palette poses a shape at the size THIS company says a rack is, so the sizes travel with the
        // context the plan screen already asks for rather than being constants the web carries of its own.
        $shapes = $this->arrayAt($this->json(), 'planShapes');
        self::assertSame(['rack', 'zone', 'aisle', 'dock'], array_column($shapes, 'shape'));
        self::assertSame(['6.000', '4.000'], $this->sidesOf($shapes, 1), 'a zone, at the declared default');

        // And it is RESOLVED, not recited: this company's racks are 2,40 m long, and the palette says so. Asserting
        // only the declared default would pass just as well against sizes hardcoded in the provider.
        // Re-found, never reused: the test client reboots the kernel between requests, so the company held since
        // setUp belongs to an entity manager that is gone.
        $mineNow = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($mineNow);
        $this->em()->persist(new Setting(SettingAddress::company($mineNow), 'venue.shape.rack.width', '2.400', new \DateTimeImmutable()));
        $this->em()->flush();
        $this->getJson($this->path('stock-options'));
        self::assertResponseIsSuccessful();
        self::assertSame(['2.400', '0.600'], $this->sidesOf($this->arrayAt($this->json(), 'planShapes'), 0), 'the company’s own length, the declared depth');

        foreach ([
            'productId' => ['operation' => 'receive', 'productId' => $this->supportId, 'locationId' => $siteId, 'quantity' => '1'],
            'quantity' => ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $siteId, 'quantity' => '1.5'],
            'locationId' => ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => '0192c3a4-0000-7000-8000-000000000000', 'quantity' => '1'],
            'operation' => ['operation' => 'steal', 'productId' => $this->laptopId, 'locationId' => $siteId, 'quantity' => '1'],
        ] as $field => $body) {
            $this->postJson($this->path('stock-movements'), $body);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
        }
    }

    /**
     * The one thing that makes this picker different from the document forms': stock is kept only of GOODS whose
     * tracking setting is on, which is half a column and half a setting — so what is offered is narrower than what
     * the words find. What a movement already NAMES is answered by id regardless, because the movement happened.
     */
    public function testTheProductPickerOffersOnlyWhatStockIsKeptOf(): void
    {
        $this->signedIn(['stock.read']);

        $this->getJson($this->path('stock-options/products'));

        self::assertResponseIsSuccessful();
        $picks = $this->jsonList();
        self::assertSame(['id', 'reference', 'name', 'unitCode', 'unitDecimals', 'homeLocationId', 'tracking'], array_keys($picks[0]));
        self::assertSame(['none'], array_column($picks, 'tracking'));
        self::assertSame(['ART-001'], array_column($picks, 'reference'), 'neither a service nor untracked goods');
        self::assertSame([['C62'], [0]], [array_column($picks, 'unitCode'), array_column($picks, 'unitDecimals')]);

        $this->getJson($this->path('stock-options/products').'?q=assistance');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList(), 'the words find it, the kind keeps it out');

        // The half no column can answer: goods, found by its words, whose own tracking setting is off.
        $this->getJson($this->path('stock-options/products').'?q=cartouche');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList(), 'the words find it, the setting keeps it out');

        foreach ([$this->supportId, $this->consumableId] as $id) {
            $this->getJson($this->path('stock-options/products').'?ids[]='.$id);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $this->jsonList(), 'what a movement names is answered whatever is kept of it now');
        }

        // A receipt of a tracked product asks for its lot, so the picker says how the product is tracked.
        $laptop = $this->em()->find(Product::class, $this->laptopId);
        self::assertNotNull($laptop);
        $laptop->track(ProductTracking::Lot, new \DateTimeImmutable());
        $this->em()->flush();
        $this->getJson($this->path('stock-options/products').'?ids[]='.$this->laptopId);
        self::assertResponseIsSuccessful();
        self::assertSame(['lot'], array_column($this->jsonList(), 'tracking'));
    }

    public function testSomebodyWithoutTheStockPermissionDoesNotPickAProduct(): void
    {
        $this->signedIn(['product.read']);

        $this->getJson($this->path('stock-options/products'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testADeliveryNoteTakesStockOutWhenValidatedAndReturnsItWhenCancelled(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $siteId = $this->defaultLocationId();
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $siteId, 'quantity' => '10']);
        $customerId = $this->customer();

        $noteId = $this->validatedNote($customerId, '3');
        self::assertSame(['7.000'], array_column($this->levels(), 'quantity'));

        $this->postJson($this->path('delivery-notes', $noteId).'/cancel', null);
        self::assertResponseIsSuccessful();
        self::assertSame(['10.000'], array_column($this->levels(), 'quantity'));
        $this->getJson($this->path('stock-movements').'?productId='.$this->laptopId);
        self::assertSame([['in', 'delivery_note', $noteId], ['out', 'delivery_note', $noteId], ['in', 'receipt', null]], array_map(static fn (array $m) => [$m['kind'], $m['sourceType'], $m['sourceId']], $this->jsonList()));

        $this->em()->persist(ModuleState::of($this->company(), 'inventory', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->validatedNote($customerId, '2');
        self::assertEquals(3, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM stock_movement'), 'a company with inventory off moves no stock');
        $this->getJson($this->path('stock-levels'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testADirectInvoiceTakesItsGoodsOutWhenIssuedAndOneBuiltFromANoteTakesNothingMore(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate', 'invoice.read', 'invoice.write', 'invoice.issue']);
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $this->defaultLocationId(), 'quantity' => '10']);
        $customerId = $this->customer();

        $direct = $this->issuedInvoice($customerId, [['productId' => $this->laptopId, 'quantity' => '3'], ['productId' => $this->supportId, 'quantity' => '1']]);
        self::assertSame(['7.000'], array_column($this->levels(), 'quantity'), 'the goods leave, the service has no stock to leave');
        $this->getJson($this->path('stock-movements').'?productId='.$this->laptopId);
        self::assertSame([['out', 'invoice', $direct], ['in', 'receipt', null]], array_map(static fn (array $m) => [$m['kind'], $m['sourceType'], $m['sourceId']], $this->jsonList()));
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM stock_movement WHERE product_id = ?', [$this->supportId]));

        $note = $this->validatedNote($customerId, '2');
        self::assertSame(['5.000'], array_column($this->levels(), 'quantity'));
        $this->postJson($this->path('invoices/from-delivery-notes'), ['deliveryNoteIds' => [$note]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $fromNote = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('invoices', $fromNote).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        self::assertSame(['5.000'], array_column($this->levels(), 'quantity'), 'the note took those goods out already');
        self::assertEquals(1, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM stock_movement WHERE source_type = 'invoice'"));
    }

    public function testAReceiptMovesTheProductsCostAsTheCompanysSettingSaysAndKeepsItsHistory(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.cost.read']);
        $site = $this->defaultLocationId();
        $receive = fn (string $quantity, string $cost, ?string $apply = null) => $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $site, 'quantity' => $quantity, 'unitCost' => $cost, 'applyCost' => $apply]);
        $cost = fn (): mixed => $this->em()->getConnection()->fetchOne('SELECT cost_price FROM product WHERE id = ?', [$this->laptopId]);
        $rows = fn (): mixed => $this->em()->getConnection()->fetchOne('SELECT count(*) FROM product_cost_change WHERE product_id = ?', [$this->laptopId]);

        $receive('10', '1000');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([null, 0], [$cost(), $rows()], 'the default offers a choice and nothing was chosen');
        $receive('10', '1300', 'last');
        self::assertSame(['1300.0000', 1], [$cost(), $rows()]);

        $this->em()->persist(new Setting(SettingAddress::company($this->company()), 'stock.cost_on_receive', 'average', new \DateTimeImmutable()));
        $this->em()->flush();
        $receive('20', '1400');
        self::assertSame(['1275.0000', 2], [$cost(), $rows()], '(10 x 1000 + 10 x 1300 + 20 x 1400) over 40, worked out in SQL');
        $history = $this->em()->getConnection()->fetchAssociative('SELECT old_cost, new_cost, source FROM product_cost_change ORDER BY at DESC, id DESC LIMIT 1');
        self::assertIsArray($history);
        self::assertSame(['1300.0000', '1275.0000', 'receipt'], [$history['old_cost'] ?? null, $history['new_cost'] ?? null, $history['source'] ?? null]);

        $this->createUser('clerk@twes.local', 'password-1234', $this->company(), ['stock.read', 'stock.write'], 'counter');
        $this->login('clerk@twes.local', 'password-1234');
        $receive('5', '9000', 'last');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['1275.0000', 2], [$cost(), $rows()], 'a writer who cannot read costs neither types one nor applies one');
    }

    /**
     * A receipt recorded by someone who may not read costs keeps its cost « à compléter »: a cost reader is asked for it on
     * « À surveiller », and once it is entered the weighted average and the product cost move (docs/SPEC.md § 7, audit
     * 2026-10-06 C challenge 9).
     */
    public function testAReceiptBySomeoneWhoCannotReadCostsWaitsForACostReaderWhoCompletesIt(): void
    {
        $this->em()->persist(new Setting(SettingAddress::company($this->company()), 'stock.cost_on_receive', 'average', new \DateTimeImmutable()));
        $this->em()->flush();
        $this->signedIn(['company.read', 'stock.read', 'stock.write', 'product.cost.read']);
        $site = $this->defaultLocationId();
        $receive = fn (string $quantity, ?string $cost) => $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $site, 'quantity' => $quantity, 'unitCost' => $cost]);
        $cost = fn (): mixed => $this->em()->getConnection()->fetchOne('SELECT cost_price FROM product WHERE id = ?', [$this->laptopId]);
        $toComplete = $this->path('watch/stock.receipt_cost_to_complete');
        $receive('10', '1000');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('1000.0000', $cost());
        $receive('4', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'a cost reader leaving the cost out is not asked for it again');

        $this->createUser('clerk@twes.local', 'password-1234', $this->company(), ['company.read', 'stock.read', 'stock.write'], 'counter');
        $this->login('clerk@twes.local', 'password-1234');
        $receive('10', '9000');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $receiptId = $this->stringAt($this->json(), 'id');
        self::assertSame('1000.0000', $cost(), 'valued at the average meanwhile, the typed cost ignored');
        $this->postJson($this->path('stock-receipts'), ['productId' => $this->laptopId, 'parts' => [['locationId' => $site, 'quantity' => '2']], 'unitCost' => '9000']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $splitId = $this->stringAt($this->json(), 'id');
        $this->getJson($toComplete);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the subject is a cost reader\'s');
        $complete = fn (string $id, string $unitCost) => $this->postJson($this->path('stock-movements', $id).'/cost', ['unitCost' => $unitCost]);
        $complete($receiptId, '1200');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'nor may a writer who cannot read costs enter one');

        $this->login('stock@twes.local', 'password-1234');
        $this->getJson($toComplete);
        self::assertResponseIsSuccessful();
        self::assertSame([$receiptId, $splitId], array_column($this->jsonList(), 'subjectId'), 'only the receipts nobody could cost, a split one too, oldest first');
        $complete($receiptId, '12x');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $complete($receiptId, '1200');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['costTyped'] ?? null);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT unit_cost, cost_typed, cost_to_complete FROM stock_movement WHERE id = ?', [$receiptId]);
        self::assertSame(['unit_cost' => '1200.0000', 'cost_typed' => true, 'cost_to_complete' => false], $row);
        self::assertSame('1076.9231', $cost(), '(10 x 1000 + 4 x 1000 + 10 x 1200 + 2 x 1000) over 26: the average moves once the cost is known');
        $this->getJson($toComplete);
        self::assertSame([$splitId], array_column($this->jsonList(), 'subjectId'), 'asked once, answered once');
        $complete($receiptId, '1300');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a cost entered is a cost, not a question any more');

        $other = $this->createCompany('Globex');
        $this->postJson('/api/companies/'.$other->getId()->toRfc4122().'/stock-movements/'.$receiptId.'/cost', ['unitCost' => '1']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company');
    }

    /**
     * A cost entered after some of the receipt's goods have left is split (docs/SPEC.md § 7, the B-F4 ruling): the share
     * that went with them is booked once, that day, as a correction of the cost of sales, and the rest stays on the stock.
     * 10 received at a provisional 5, 5 gone, 8 entered: 5 left worth 40, a cost of 8, and 15 to the cost of sales.
     */
    public function testACostEnteredAfterSomeOfTheGoodsLeftSplitsTheDifferenceBetweenTheStockAndTheCostOfSales(): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE product SET cost_price = 5 WHERE id = ?', [$this->laptopId]);
        $this->em()->persist(new Setting(SettingAddress::company($this->company()), 'stock.cost_on_receive', 'average', new \DateTimeImmutable()));
        $this->em()->flush();
        $this->createUser('clerk@twes.local', 'password-1234', $this->company(), ['company.read', 'stock.read', 'stock.write'], 'counter');
        $this->signedIn(['company.read', 'stock.read', 'stock.write', 'product.cost.read']);
        $site = $this->defaultLocationId();

        $this->login('clerk@twes.local', 'password-1234');
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $site, 'quantity' => '10']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $receiptId = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('stock-movements'), ['operation' => 'loss', 'productId' => $this->laptopId, 'locationId' => $site, 'quantity' => '5', 'reason' => 'broken', 'note' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        // A move to another shelf takes nothing out of the stock: it is no share of the sales.
        $this->postJson($this->path('stock-locations'), $this->location(['kind' => 'rack', 'code' => 'R9', 'name' => 'Rayon 9']));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postJson($this->path('stock-movements'), ['operation' => 'move', 'productId' => $this->laptopId, 'locationId' => $site, 'toLocationId' => $this->stringAt($this->json(), 'id'), 'quantity' => '3']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->login('stock@twes.local', 'password-1234');
        $this->postJson($this->path('stock-movements', $receiptId).'/cost', ['unitCost' => '8']);
        self::assertResponseIsSuccessful();

        $value = $this->em()->getConnection()->fetchOne('SELECT SUM(quantity * unit_cost + COALESCE(revaluation, 0)) FROM stock_movement WHERE product_id = ? AND unit_cost IS NOT NULL', [$this->laptopId]);
        self::assertEquals(40, $value, 'the five left are worth what they cost');
        self::assertSame('8.0000', $this->em()->getConnection()->fetchOne('SELECT cost_price FROM product WHERE id = ?', [$this->laptopId]));
        $corrections = $this->em()->getConnection()->fetchAllAssociative("SELECT quantity, revaluation::numeric(14, 4) AS revaluation, source_id FROM stock_movement WHERE source_type = 'cost_correction'");
        self::assertSame([['quantity' => '0.000', 'revaluation' => '-15.0000', 'source_id' => $receiptId]], $corrections, 'the share of the goods gone, booked once against the receipt');

        $this->getJson($this->path('stock-movements').'?sourceType=cost_correction');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonList(), 'the correction is in the stock history, under its own kind');
    }

    public function testTheReceiptCostAndTheCostHistoryAreReadOnlyWithTheCostPermission(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.cost.read']);
        $site = $this->defaultLocationId();
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $site, 'quantity' => '10', 'unitCost' => '1200', 'applyCost' => 'last']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $typedAt = $this->em()->getConnection()->fetchOne('SELECT at FROM stock_movement WHERE cost_typed');
        self::assertIsString($typedAt);
        // Received later with no cost: valued at the average, so nobody paid that and it is no last price.
        $this->em()->getConnection()->executeStatement("UPDATE stock_movement SET at = at - interval '1 hour' WHERE cost_typed");
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $site, 'quantity' => '5']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $ask = $this->path('stock-options/receipt-cost').'?productId='.$this->laptopId.'&quantity=10&unitCost=1400';

        $this->getJson($ask);
        self::assertResponseIsSuccessful();
        $cost = $this->json();
        self::assertSame(['suggest', '1200.0000', '1280.0000', '1200.0000'], [$cost['mode'] ?? null, $cost['costNow'] ?? null, $cost['average'] ?? null, $cost['lastCost'] ?? null]);
        self::assertEquals(new \DateTimeImmutable($typedAt.' UTC')->modify('-1 hour'), new \DateTimeImmutable(\is_string($cost['lastAt'] ?? null) ? $cost['lastAt'] : 'invalid'), 'the receipt somebody typed, not the later one');

        $this->getJson($this->path('products', $this->laptopId).'/cost-history');
        self::assertResponseIsSuccessful();
        $history = $this->jsonList();
        self::assertSame([[null, '1200.0000', 'receipt']], array_map(static fn (array $row): array => [$row['oldCost'] ?? null, $row['newCost'] ?? null, $row['source'] ?? null], $history));

        // Audit 2026-10-06, E-13: what is not a plain decimal is no receipt to weigh in, never a server error.
        $this->getJson($this->path('stock-options/receipt-cost').'?productId='.$this->laptopId);
        $plain = $this->json()['average'] ?? null;
        foreach (['quantity=1e3&unitCost=1', 'quantity=%201&unitCost=1', 'quantity=10&unitCost=1e3', 'quantity=10&unitCost=-0'] as $odd) {
            $this->getJson($this->path('stock-options/receipt-cost').'?productId='.$this->laptopId.'&'.$odd);
            self::assertResponseIsSuccessful($odd);
            self::assertSame($plain, $this->json()['average'] ?? null, $odd);
        }

        $this->getJson($this->path('stock-options/receipt-cost').'?productId='.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a product of nobody');

        $this->createUser('clerk@twes.local', 'password-1234', $this->company(), ['stock.read', 'stock.write', 'product.read'], 'counter');
        $this->login('clerk@twes.local', 'password-1234');
        foreach ([$ask, $this->path('products', $this->laptopId).'/cost-history'] as $path) {
            $this->getJson($path);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $path);
        }

        $this->createUser('buyer@twes.local', 'password-1234', $this->company(), ['product.read', 'product.cost.read'], 'buyer');
        $this->login('buyer@twes.local', 'password-1234');
        $this->getJson($ask);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the cost permission alone does not open the receipt read');
        $this->getJson($this->path('products', $this->laptopId).'/cost-history');
        self::assertResponseIsSuccessful();

        $this->login('stock@twes.local', 'password-1234');
        $other = $this->createCompany('Globex');
        $this->getJson('/api/companies/'.$other->getId()->toRfc4122().'/stock-options/receipt-cost?productId='.$this->laptopId);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company');
        $this->getJson('/api/companies/'.$other->getId()->toRfc4122().'/products/'.$this->laptopId.'/cost-history');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company');
    }

    public function testACreditNoteBringsBackOnlyTheGoodsOfTheLinesMarkedReturnedToTheLotsTheSaleTookThemFrom(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit']);
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $this->defaultLocationId(), 'quantity' => '10']);
        $customerId = $this->customer();
        $direct = $this->issuedInvoice($customerId, [['productId' => $this->laptopId, 'quantity' => '3']]);
        self::assertSame(['7.000'], array_column($this->levels(), 'quantity'));
        $revise = fn (string $id, bool $returned, string $quantity) => $this->sendJson('PUT', $this->path('invoices', $id), ['customerId' => $customerId, 'establishmentId' => null, 'supplyDate' => null, 'paymentTermsDays' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'documentTaxComponentIds' => null, 'lines' => [['productId' => $this->laptopId, 'quantity' => $quantity, 'returned' => $returned]]]);
        $creditNote = function (bool $returned, string $quantity) use ($direct, $revise): string {
            $this->postJson($this->path('invoices', $direct).'/credit-notes', ['creditNoteReason' => 'Retour']);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            $id = $this->stringAt($this->json(), 'id');
            $revise($id, $returned, $quantity);
            self::assertResponseIsSuccessful();
            $this->postJson($this->path('invoices', $id).'/issue', null);
            self::assertResponseStatusCodeSame(Response::HTTP_OK);

            return $id;
        };

        $creditNote(false, '1');
        self::assertSame(['7.000'], array_column($this->levels(), 'quantity'), 'a price correction returns nothing');

        $second = $creditNote(true, '1');
        self::assertSame(['8.000'], array_column($this->levels(), 'quantity'));
        $this->getJson($this->path('stock-movements').'?productId='.$this->laptopId);
        self::assertSame([['in', 'credit_note', $second], ['out', 'invoice', $direct], ['in', 'receipt', null]], array_map(static fn (array $m) => [$m['kind'], $m['sourceType'], $m['sourceId']], $this->jsonList()));

        $this->getJson($this->path('invoices', $second));
        self::assertSame([true], array_column($this->arrayAt($this->json(), 'lines'), 'returned'));
        $this->postJson($this->path('invoices'), ['customerId' => $customerId, 'establishmentId' => null, 'supplyDate' => null, 'paymentTermsDays' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'documentTaxComponentIds' => null, 'lines' => [['productId' => $this->laptopId, 'quantity' => '1', 'returned' => true]]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'an invoice returns nothing');
        self::assertStringContainsString('lines[0].returned', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Goods an invoice sold through its delivery notes come back when its credit note marks them returned (docs/SPEC.md
     * § 7, audit 2026-10-06 E-5): the notes cannot be cancelled once invoiced, so the credit note is the only way back.
     */
    public function testACreditNoteOfAnInvoiceBuiltFromDeliveryNotesBringsTheirGoodsBackOnceOnly(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $this->defaultLocationId(), 'quantity' => '10']);
        $customerId = $this->customer();
        $notes = [$this->validatedNote($customerId, '2'), $this->validatedNote($customerId, '3')];
        $this->postJson($this->path('invoices/from-delivery-notes'), ['deliveryNoteIds' => $notes]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $invoice = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('invoices', $invoice).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['5.000'], array_column($this->levels(), 'quantity'));
        // The second asks for more goods than are left, at a price the invoice still has room to credit.
        $creditNote = function (string $quantity, ?string $price = null) use ($invoice, $customerId): string {
            $this->postJson($this->path('invoices', $invoice).'/credit-notes', ['creditNoteReason' => 'Retour']);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            $id = $this->stringAt($this->json(), 'id');
            $this->sendJson('PUT', $this->path('invoices', $id), ['customerId' => $customerId, 'establishmentId' => null, 'supplyDate' => null, 'paymentTermsDays' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'documentTaxComponentIds' => null, 'lines' => [['productId' => $this->laptopId, 'quantity' => $quantity, 'returned' => true, ...(null === $price ? [] : ['unitPriceNet' => $price])]]]);
            self::assertResponseIsSuccessful();
            $this->postJson($this->path('invoices', $id).'/issue', null);
            self::assertResponseStatusCodeSame(Response::HTTP_OK);

            return $id;
        };

        $first = $creditNote('4');
        self::assertSame(['9.000'], array_column($this->levels(), 'quantity'), 'four of the five the notes took out come back');
        $creditNote('2', '1');
        self::assertSame(['10.000'], array_column($this->levels(), 'quantity'), 'the second gets the one left, never more than the notes took out');

        $returned = $this->em()->getConnection()->fetchAllAssociative("SELECT quantity, reverses_source_id FROM stock_movement WHERE source_type = 'credit_note' ORDER BY quantity DESC");
        self::assertSame([['quantity' => '4.000', 'reverses_source_id' => $invoice], ['quantity' => '1.000', 'reverses_source_id' => $invoice]], $returned, 'counted against the invoice the notes were invoiced on');
        self::assertNotSame('', $first);
    }

    public function testANoteWhoseStockNeverMovedIsReplayedOnceByTheCommand(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $this->defaultLocationId(), 'quantity' => '10']);
        $customerId = $this->customer();
        $noteId = $this->validatedNote($customerId, '3');
        // What a failed listener leaves behind: a validated note and no movement of it.
        $this->em()->getConnection()->executeStatement("DELETE FROM stock_movement WHERE source_type = 'delivery_note'");
        self::assertSame(['10.000'], array_column($this->levels(), 'quantity'));
        $companyId = $this->company->getId()->toRfc4122();

        $first = $this->replay([$companyId, $noteId]);
        $second = $this->replay([$companyId, $noteId]);

        self::assertSame([0, 0], [$first->getStatusCode(), $second->getStatusCode()], $first->getDisplay().$second->getDisplay());
        self::assertStringContainsString('1 movement', $first->getDisplay());
        self::assertStringContainsString('already moved', $second->getDisplay());
        self::assertSame(['7.000'], array_column($this->levels(), 'quantity'));

        $draftId = $this->draftNote($customerId, '1');
        foreach ([[$companyId, $draftId], [$this->createCompany('Globex')->getId()->toRfc4122(), $noteId], [$companyId, 'not-an-id']] as $arguments) {
            self::assertSame(1, $this->replay($arguments)->getStatusCode(), implode(' ', $arguments));
        }
        self::assertSame(['7.000'], array_column($this->levels(), 'quantity'));
    }

    public function testAProductWhoseStockWasMovedKeepsItsUnitEvenWithInventoryOff(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.read', 'product.write']);
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $this->defaultLocationId(), 'quantity' => '10']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->em()->persist(ModuleState::of($this->company(), 'inventory', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $hour = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('HUR', $this->company->getId());
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($hour);
        self::assertNotNull($piece);
        $laptop = ['reference' => 'ART-001', 'name' => 'Portable 14"', 'description' => null, 'kind' => 'goods', 'unitId' => $hour->getId()->toRfc4122(), 'unitPriceNet' => '1250', 'costPrice' => null, 'categoryId' => null, 'barcodes' => [], 'defaultTaxComponentIds' => [], 'customFields' => [], 'isActive' => true];

        foreach (['unitId' => $laptop, 'kind' => [...$laptop, 'unitId' => $piece->getId()->toRfc4122(), 'kind' => 'service']] as $field => $body) {
            $this->sendJson('PUT', '/api/companies/'.$this->company->getId()->toRfc4122().'/products/'.$this->laptopId, $body);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
        }
        self::assertSame('C62', $this->em()->getConnection()->fetchOne('SELECT u.code FROM product p JOIN unit u ON u.id = p.unit_id WHERE p.id = ?', [$this->laptopId]));
        $this->sendJson('PUT', '/api/companies/'.$this->company->getId()->toRfc4122().'/products/'.$this->supportId, [...$laptop, 'reference' => 'SRV-001', 'name' => 'Assistance', 'kind' => 'service', 'unitPriceNet' => '50']);
        self::assertResponseIsSuccessful();
    }

    public function testAPageOfMovementsCostsTheSameStatementsWhateverTheRowsItHolds(): void
    {
        // A product per movement, so the identity map cannot hide a read per row; made before the first request, which
        // reboots the kernel and leaves the company detached.
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $products = [];
        foreach (range(1, 6) as $n) {
            $product = Product::create($this->company, 'ART-10'.$n, new ProductDetails('Article '.$n, null, ProductKind::Goods, '10'), $piece, null, [], new \DateTimeImmutable());
            $this->em()->persist($product);
            $products[] = $product->getId()->toRfc4122();
        }
        $this->em()->flush();
        $this->signedIn(['stock.read', 'stock.write']);
        $siteId = $this->defaultLocationId();
        foreach ($products as $productId) {
            $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $productId, 'locationId' => $siteId, 'quantity' => '3']);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }
        $this->em()->clear();

        $statements = [1 => $this->statementsForAPageOf($this->path('stock-movements'), 1), 6 => $this->statementsForAPageOf($this->path('stock-movements'), 6)];

        self::assertSame($statements[1], $statements[6], 'six rows cost what one does (audit PF-07)');
        // Measured 11 on 2026-09-25 (18 for six rows before), the session and the company's checks included, plus one:
        // the read of whether the person ended this session (the connected devices screen), made on every signed-in request.
        self::assertLessThanOrEqual(12, $statements[6]);
    }

    public function testTrackingIsChosenBeforeTheFirstMovementAndKeptAfterIt(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.read', 'product.write']);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $products = '/api/companies/'.$this->company->getId()->toRfc4122().'/products';
        $laptop = ['reference' => 'ART-001', 'name' => 'Portable 14"', 'description' => null, 'kind' => 'goods', 'unitId' => $piece->getId()->toRfc4122(), 'unitPriceNet' => '1250', 'costPrice' => null, 'categoryId' => null, 'defaultTaxComponentIds' => [], 'customFields' => [], 'isActive' => true];

        $this->getJson($products.'/'.$this->laptopId);
        self::assertSame('none', $this->json()['tracking']);
        $this->sendJson('PUT', $products.'/'.$this->laptopId, [...$laptop, 'tracking' => 'serial']);
        self::assertResponseIsSuccessful();
        self::assertSame('serial', $this->json()['tracking']);
        $this->sendJson('PUT', $products.'/'.$this->laptopId, $laptop);
        self::assertSame('serial', $this->json()['tracking'], 'a body without tracking keeps it');
        $this->sendJson('PUT', $products.'/'.$this->laptopId, [...$laptop, 'tracking' => 'batch']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $this->defaultLocationId(), 'quantity' => '1', 'lotCode' => 'SN-0001']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->sendJson('PUT', $products.'/'.$this->laptopId, [...$laptop, 'tracking' => 'lot']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('tracking', (string) $this->client->getResponse()->getContent());
        self::assertSame('serial', $this->em()->getConnection()->fetchOne('SELECT tracking FROM product WHERE id = ?', [$this->laptopId]));

        $this->sendJson('PUT', $products.'/'.$this->supportId, [...$laptop, 'reference' => 'SRV-001', 'name' => 'Assistance', 'kind' => 'service', 'unitPriceNet' => '50', 'tracking' => 'lot']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a service tracks nothing');
    }

    /** Row 63 slice 10: a recall starts from a lot or serial code and finds everything that moved it. */
    public function testALotsMovementsAreFoundByItsCodeWhateverItsCase(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.read', 'product.write']);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $this->sendJson('PUT', '/api/companies/'.$this->company->getId()->toRfc4122().'/products/'.$this->laptopId, ['reference' => 'ART-001', 'name' => 'Portable 14"', 'description' => null, 'kind' => 'goods', 'unitId' => $piece->getId()->toRfc4122(), 'unitPriceNet' => '1250', 'costPrice' => null, 'categoryId' => null, 'defaultTaxComponentIds' => [], 'customFields' => [], 'isActive' => true, 'tracking' => 'lot']);
        self::assertResponseIsSuccessful();
        $receipt = ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $this->defaultLocationId()];
        foreach ([['L-2408', '4'], ['L-2409', '2'], ['L-2408', '1']] as [$lot, $quantity]) {
            $this->postJson($this->path('stock-movements'), [...$receipt, 'quantity' => $quantity, 'lotCode' => $lot]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }

        $this->getJson($this->path('stock-movements').'?lot=l-2408');
        self::assertResponseIsSuccessful();
        self::assertSame(['1.000', '4.000'], array_column($this->jsonList(), 'quantity'), 'both receipts of the lot, newest first');
        self::assertSame(['L-2408', 'L-2408'], array_column($this->jsonList(), 'lotCode'));

        $this->getJson($this->path('stock-movements').'?lot=L-2409');
        self::assertSame(['2.000'], array_column($this->jsonList(), 'quantity'));
        $this->getJson($this->path('stock-movements').'?lot=L-24');
        self::assertSame([], $this->jsonList(), 'a code is matched whole, not as a prefix');
    }

    public function testGoodsTrackedByLotAreReceivedUnderTheirLotAndListedPerLot(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.read', 'product.write']);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $this->sendJson('PUT', '/api/companies/'.$this->company->getId()->toRfc4122().'/products/'.$this->laptopId, ['reference' => 'ART-001', 'name' => 'Portable 14"', 'description' => null, 'kind' => 'goods', 'unitId' => $piece->getId()->toRfc4122(), 'unitPriceNet' => '1250', 'costPrice' => null, 'categoryId' => null, 'defaultTaxComponentIds' => [], 'customFields' => [], 'isActive' => true, 'tracking' => 'lot']);
        self::assertResponseIsSuccessful();
        $site = $this->defaultLocationId();
        $receipt = ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $site];

        $this->postJson($this->path('stock-movements'), [...$receipt, 'quantity' => '5', 'lotCode' => 'L2609', 'lotExpiresOn' => '2027-03-31']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['L2609', '2027-03-31'], [$this->json()['lotCode'], $this->json()['lotExpiresOn']]);
        $this->postJson($this->path('stock-movements'), [...$receipt, 'quantity' => '2', 'lotCode' => 'L2609']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postJson($this->path('stock-movements'), [...$receipt, 'quantity' => '3', 'lotCode' => 'L2610']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        foreach ([
            'lot' => [...$receipt, 'quantity' => '1'],
            'lotExpiresOn' => [...$receipt, 'quantity' => '1', 'lotCode' => 'L2609', 'lotExpiresOn' => '2027-04-30'],
            'lotCode' => [...$receipt, 'quantity' => '1', 'lotCode' => 'L 2609'],
        ] as $field => $body) {
            $this->postJson($this->path('stock-movements'), $body);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field.':', (string) $this->client->getResponse()->getContent(), $field);
        }
        $this->postJson($this->path('stock-movements'), [...$receipt, 'quantity' => '1', 'lotCode' => 'L2611', 'lotExpiresOn' => '31/03/2027']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a date is a date');

        $levels = array_map(static fn (array $row): array => [$row['lotCode'], $row['lotExpiresOn'], $row['quantity']], $this->levels());
        sort($levels);
        self::assertSame([['L2609', '2027-03-31', '7.000'], ['L2610', null, '3.000']], $levels);
        self::assertEquals(2, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM stock_lot'), 'a refused receipt opened no lot');
    }

    public function testADeliveryNoteLineNamingItsLotTakesThatLotRatherThanTheFirstToExpire(): void
    {
        // docs/SPEC.md § 7, 2026-09-24 12:40 row 5.
        $this->signedIn(['stock.read', 'stock.write', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $laptop = $this->em()->find(Product::class, $this->laptopId);
        self::assertNotNull($laptop);
        $laptop->track(ProductTracking::Lot, new \DateTimeImmutable());
        $this->em()->flush();
        $site = $this->defaultLocationId();
        $today = new \DateTimeImmutable('today', new \DateTimeZone($this->company->getTimezone()));
        foreach ([['LATER', '+60 days', '5'], ['SOON', '+10 days', '2']] as [$code, $offset, $quantity]) {
            $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $site, 'quantity' => $quantity, 'lotCode' => $code, 'lotExpiresOn' => $today->modify($offset)->format('Y-m-d')]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED, $code);
        }
        $customerId = $this->customer();

        $noteId = $this->draftNote($customerId, '3', [['productId' => $this->laptopId, 'quantity' => '3', 'lotCode' => ' later ']]);
        $lines = $this->arrayAt($this->json(), 'lines');
        self::assertCount(1, $lines);
        self::assertIsArray($lines[0] ?? null);
        self::assertSame(['later', 'lot'], [$lines[0]['lotCode'] ?? null, $lines[0]['productTracking'] ?? null], 'the lot as typed, trimmed');
        $this->postJson($this->path('delivery-notes', $noteId).'/validate', null);
        self::assertResponseIsSuccessful();

        self::assertSame(['LATER' => '2.000', 'SOON' => '2.000'], $this->levelsByLot(), 'the lot handed over, not the first to expire');
        $this->getJson($this->path('stock-movements').'?lot=LATER');
        self::assertSame([['out', $noteId], ['in', null]], array_map(static fn (array $m): array => [$m['kind'], $m['sourceId']], $this->jsonList()), 'the recall search names the note that took it');

        $unitId = $this->em()->getConnection()->fetchOne("SELECT id FROM unit WHERE code = 'C62' AND company_id = ?", [$this->company->getId()->toRfc4122()]);
        self::assertIsString($unitId);
        $this->postJson($this->path('delivery-notes'), ['customerId' => $customerId, 'lines' => [['description' => 'Pose', 'quantity' => '1', 'unitId' => $unitId, 'unitPriceNet' => '10', 'taxComponentIds' => [], 'lotCode' => 'L-1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a line with no product names no lot');
        self::assertStringContainsString('lines[0].lotCode', (string) $this->client->getResponse()->getContent());
    }

    public function testADeliveryNoteTakesTheFirstLotsToExpireAndAnExpiredOneOnlyOnceReleased(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $laptop = $this->em()->find(Product::class, $this->laptopId);
        self::assertNotNull($laptop);
        $laptop->track(ProductTracking::Lot, new \DateTimeImmutable());
        $this->em()->flush();
        $site = $this->defaultLocationId();
        $today = new \DateTimeImmutable('today', new \DateTimeZone($this->company->getTimezone()));
        foreach ([['LATER', '+60 days', '5'], ['SOON', '+10 days', '2'], ['EXPIRED', '-3 days', '4']] as [$code, $offset, $quantity]) {
            $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $site, 'quantity' => $quantity, 'lotCode' => $code, 'lotExpiresOn' => $today->modify($offset)->format('Y-m-d')]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED, $code);
        }
        $customerId = $this->customer();

        $this->validatedNote($customerId, '3');
        self::assertSame(['EXPIRED' => '4.000', 'LATER' => '4.000', 'SOON' => '0.000'], $this->levelsByLot());

        $expiredId = $this->em()->getConnection()->fetchOne("SELECT id FROM stock_lot WHERE code = 'EXPIRED'");
        self::assertIsString($expiredId);
        $laterId = $this->em()->getConnection()->fetchOne("SELECT id FROM stock_lot WHERE code = 'LATER'");
        self::assertIsString($laterId);
        self::assertSame(['EXPIRED' => false, 'LATER' => false, 'SOON' => false], $this->releasedByLot(), 'nothing released yet');
        $this->postJson($this->path('stock-lots', $laterId).'/release', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a lot in date is not released');
        $this->postJson($this->path('stock-lots', '0192f5c8-0000-7000-8000-000000000000').'/release', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a lot of no company of ours');
        $this->postJson($this->path('stock-lots', $expiredId).'/release', null);
        self::assertResponseIsSuccessful();
        $releasedBy = $this->em()->getConnection()->fetchOne("SELECT id FROM \"user\" WHERE email = 'stock@twes.local'");
        self::assertSame(['EXPIRED', $releasedBy], [$this->json()['code'], $this->json()['releasedBy']]);
        self::assertNotNull($this->json()['releasedAt']);
        self::assertSame(['EXPIRED' => true, 'LATER' => false, 'SOON' => false], $this->releasedByLot(), 'the stock levels say which lots were released');

        $this->validatedNote($customerId, '5');
        self::assertSame(['EXPIRED' => '0.000', 'LATER' => '3.000', 'SOON' => '0.000'], $this->levelsByLot(), 'released, the expired lot left first');
        self::assertEquals(0, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM stock_movement WHERE source_type = 'delivery_note' AND lot_id IS NULL"));
    }

    public function testTheSourceKeyHoldsAnUntrackedDeliveryOnceWhileReceiptsRepeatFreely(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $site = $this->defaultLocationId();
        foreach (['4', '6'] as $quantity) {
            $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $site, 'quantity' => $quantity]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'two receipts of one product at one location are two rows');
        }
        $this->validatedNote($this->customer(), '3');
        $connection = $this->em()->getConnection();

        // The test runs inside a transaction a violation aborts, so the probe gets a savepoint of its own to fall back to.
        $connection->executeStatement('SAVEPOINT duplicate_delivery');
        try {
            $connection->executeStatement("INSERT INTO stock_movement (id, company_id, product_id, location_id, lot_id, kind, quantity, source_type, source_id, recorded_by, at) SELECT gen_random_uuid(), company_id, product_id, location_id, lot_id, kind, quantity, source_type, source_id, recorded_by, at FROM stock_movement WHERE source_type = 'delivery_note'");
            self::fail('The same delivery was written twice for a product that names no lot.');
        } catch (UniqueConstraintViolationException) {
            $connection->executeStatement('ROLLBACK TO SAVEPOINT duplicate_delivery');
            self::assertEquals(1, $connection->fetchOne("SELECT count(*) FROM stock_movement WHERE source_type = 'delivery_note'"));
        }
    }

    /** @return array<string, string> each lot's code and its stock, by code */
    private function levelsByLot(): array
    {
        $levels = [];
        foreach ($this->levels() as $row) {
            $levels[$this->stringAt($row, 'lotCode')] = $this->stringAt($row, 'quantity');
        }
        ksort($levels);

        return $levels;
    }

    /** @return array<string, bool> */
    private function releasedByLot(): array
    {
        $released = [];
        foreach ($this->levels() as $row) {
            $released[$this->stringAt($row, 'lotCode')] = true === $row['lotReleased'];
        }
        ksort($released);

        return $released;
    }

    public function testWithoutThePermissionOrForAnotherCompanyNothingIsFound(): void
    {
        $this->signedIn(['stock.read']);
        $siteId = $this->defaultLocationId();

        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $siteId, 'quantity' => '1']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path('stock-locations'), $this->location(['kind' => 'zone', 'code' => 'Z1', 'name' => 'Zone']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('DELETE', $this->path('stock-locations', $siteId));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $globex = $this->createCompany('Globex');
        foreach (['stock-locations', 'stock-levels', 'stock-movements', 'stock-options'] as $resource) {
            $this->getJson('/api/companies/'.$globex->getId()->toRfc4122().'/'.$resource);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $resource);
        }
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM stock_movement'));
    }

    /** @param array{string, string} $arguments company id and delivery note id */
    private function replay(array $arguments): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel ?? throw new \LogicException('kernel not booted')))->find('app:stock:replay-delivery-note'));
        $tester->execute(['company' => $arguments[0], 'delivery-note' => $arguments[1]]);

        return $tester;
    }

    /** A validated note delivering the laptop to the customer; its id. */
    private function validatedNote(string $customerId, string $quantity): string
    {
        $noteId = $this->draftNote($customerId, $quantity);
        $this->postJson($this->path('delivery-notes', $noteId).'/validate', null);
        self::assertResponseIsSuccessful();

        return $noteId;
    }

    /**
     * Drafts and issues an invoice of the given lines; its id.
     *
     * @param list<array<string, mixed>> $lines
     */
    private function issuedInvoice(string $customerId, array $lines): string
    {
        $this->postJson($this->path('invoices'), [
            'customerId' => $customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => null,
            'lines' => $lines,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('invoices', $id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $id;
    }

    /** A draft note delivering the laptop to the customer; its id. */
    /** @param list<array<string, mixed>>|null $lines */
    private function draftNote(string $customerId, string $quantity, ?array $lines = null): string
    {
        $this->postJson($this->path('delivery-notes'), [
            'customerId' => $customerId,
            'establishmentId' => null,
            'deliveryDate' => null,
            'deliveryAddressLine1' => null,
            'deliveryAddressLine2' => null,
            'deliveryPostalCode' => null,
            'deliveryCity' => null,
            'deliveryCountryCode' => null,
            'customerReference' => null,
            'remarksPrinted' => null,
            'notesInternal' => null,
            'lines' => $lines ?? [['productId' => $this->laptopId, 'quantity' => $quantity]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function customer(): string
    {
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $now = new \DateTimeImmutable();
        $customer = Customer::create($this->company(), 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], $now);
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer->getId()->toRfc4122();
    }

    /** The company as the entity manager knows it now: the test client reboots the kernel between requests. */
    private function company(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $company;
    }

    /** « Quarantaine » (docs/SPEC.md § 7 2026-09-19 23:25): a place for goods awaiting a decision, a kind like any other. */
    public function testAQuarantineIsAKindOfLocationGoodsAwaitingADecisionAreKeptIn(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);

        $this->postJson($this->path('stock-locations'), $this->location(['kind' => 'quarantine', 'code' => 'QRT', 'name' => 'Quarantaine']));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('quarantine', $this->json()['kind']);
    }

    /**
     * A move is ONE operation that writes TWO movements (docs/SPEC.md § 5 G10, row 74): the goods leave one location
     * and arrive at another in the same transaction, sharing a move id, so no reading of the stock ever sees half of it.
     */
    public function testGoodsAreMovedBetweenLocationsAsOneLinkedPair(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $site = $this->defaultLocationId();
        $this->postJson($this->path('stock-locations'), $this->location(['kind' => 'rack', 'code' => 'R7', 'name' => 'Rayonnage 7']));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $rack = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $site, 'quantity' => '10']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson($this->path('stock-movements'), ['operation' => 'move', 'productId' => $this->laptopId, 'locationId' => $site, 'toLocationId' => $rack, 'quantity' => '4']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        // The answer is what LEFT: it is the location the person acted on, and its sourceId names the move both
        // halves carry, so the pair is findable from it.
        self::assertSame(['out', '-4.000', 'move', $site], [$this->json()['kind'], $this->json()['quantity'], $this->json()['sourceType'], $this->json()['locationId']]);
        $moveId = $this->stringAt($this->json(), 'sourceId');

        $this->getJson($this->path('stock-movements').'?productId='.$this->laptopId.'&sourceType=move');
        self::assertSame(['4.000', '-4.000'], array_column($this->jsonList(), 'quantity'));
        self::assertSame([$moveId, $moveId], array_column($this->jsonList(), 'sourceId'), 'both halves name the same move');
        self::assertSame([$rack, $site], array_column($this->jsonList(), 'locationId'));

        $this->getJson($this->path('stock-levels'));
        $levels = array_map(static fn (array $row) => [$row['locationId'], $row['quantity']], $this->jsonList());
        usort($levels, static fn (array $a, array $b) => $a[1] <=> $b[1]);
        self::assertSame([[$rack, '4.000'], [$site, '6.000']], $levels, 'ten are still there, in two places');

        foreach ([
            ['toLocationId', ['locationId' => $site, 'toLocationId' => $site, 'quantity' => '1']],
            // A move with nowhere to go is REFUSED, not carried into the use case: without the validator firing here
            // the processor would read a null destination and answer 500 to somebody who left the select blank.
            ['toLocationId', ['locationId' => $site, 'quantity' => '1']],
            ['quantity', ['locationId' => $site, 'toLocationId' => $rack, 'quantity' => '7']],
        ] as [$field, $changes]) {
            $this->postJson($this->path('stock-movements'), ['operation' => 'move', 'productId' => $this->laptopId, ...$changes]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
        }
        // Refused means refused: nothing of either attempt was written.
        $this->getJson($this->path('stock-movements').'?productId='.$this->laptopId.'&sourceType=move');
        self::assertSame(2, $this->jsonPage()['totalItems']);
    }

    /**
     * A loss takes goods out WITH its reason (docs/SPEC.md § 7 2026-09-19 23:25): the reason is what tells a breakage
     * from a theft in a report, so a loss without one is refused, and it never stands in for a count.
     */
    public function testAWriteOffTakesGoodsOutWithItsReasonAndNoteAndRefusesWhatCannotBeLost(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $site = $this->defaultLocationId();
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $site, 'quantity' => '10']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson($this->path('stock-movements'), ['operation' => 'loss', 'productId' => $this->laptopId, 'locationId' => $site, 'quantity' => '3', 'reason' => 'broken', 'note' => 'Dropped at the counter']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['out', '-3.000', 'loss', 'broken', 'Dropped at the counter'], [$this->json()['kind'], $this->json()['quantity'], $this->json()['sourceType'], $this->json()['reason'], $this->json()['note']]);
        $this->getJson($this->path('stock-levels'));
        self::assertSame(['7.000'], array_column($this->jsonList(), 'quantity'));
        $this->getJson($this->path('stock-movements').'?sourceType=loss');
        self::assertSame([['broken', 'Dropped at the counter']], array_map(static fn (array $row) => [$row['reason'], $row['note']], $this->jsonList()));

        foreach ([
            'reason' => ['quantity' => '1'],
            'reason ' => ['quantity' => '1', 'reason' => 'vanished'],
            'quantity' => ['quantity' => '8', 'reason' => 'lost'],
            'note' => ['quantity' => '1', 'reason' => 'lost', 'note' => str_repeat('n', 501)],
        ] as $field => $body) {
            $this->postJson($this->path('stock-movements'), ['operation' => 'loss', 'productId' => $this->laptopId, 'locationId' => $site, ...$body]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString(trim($field), (string) $this->client->getResponse()->getContent());
        }
        $this->getJson($this->path('stock-levels'));
        self::assertSame(['7.000'], array_column($this->jsonList(), 'quantity'), 'a refused loss wrote nothing');
    }

    /**
     * The two sides of one palette shape, as the answer carries them: a `SettingType::Decimal` crosses the wire as
     * a decimal string, so anything else is a contract the provider and this test no longer agree on.
     *
     * @param array<mixed> $shapes
     *
     * @return array{0: string, 1: string}
     */
    private function sidesOf(array $shapes, int $at): array
    {
        $shape = $shapes[$at] ?? null;
        self::assertIsArray($shape, "the palette has a shape at $at");
        $width = $shape['width'] ?? null;
        $depth = $shape['depth'] ?? null;
        self::assertIsString($width, 'a width');
        self::assertIsString($depth, 'a depth');

        return [$width, $depth];
    }

    /** @param array<string, mixed> $movement */
    private function moved(array $movement): void
    {
        $this->postJson($this->path('stock-movements'), ['productId' => $this->laptopId, ...$movement]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
    }

    private function defaultLocationId(): string
    {
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();

        return $this->stringAt($this->jsonList()[0], 'id');
    }

    /**
     * docs/SPEC.md § 7, 2026-10-06 00:15, row 197: the movements list combines its filters (the values of one OR'd, the
     * filters AND'd), a location stands for every location under it, and the day is the company's own.
     */
    public function testEveryFilterOfTheMovementsListCombines(): void
    {
        // No « voir les coûts »: a receipt is then filed with its cost left « à compléter ».
        $this->signedIn(['stock.read', 'stock.write']);
        $site = $this->defaultLocationId();
        $this->postJson($this->path('stock-locations'), $this->location(['parentId' => $site, 'kind' => 'rack', 'code' => 'R1', 'name' => 'Rayon 1']));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $rack = $this->stringAt($this->json(), 'id');
        $this->moved(['operation' => 'receive', 'locationId' => $site, 'quantity' => '10']);
        $this->moved(['operation' => 'receive', 'locationId' => $rack, 'quantity' => '5']);
        $this->moved(['operation' => 'count', 'locationId' => $site, 'quantity' => '8']);
        $this->moved(['operation' => 'loss', 'locationId' => $rack, 'quantity' => '1', 'reason' => 'broken', 'note' => null]);
        $this->moved(['operation' => 'loss', 'locationId' => $site, 'quantity' => '1', 'reason' => 'stolen', 'note' => null]);
        $today = new \DateTimeImmutable('today', new \DateTimeZone($this->company()->getTimezone()));
        $day = static fn (string $shift): string => $today->modify($shift)->format('Y-m-d');
        // Newest first: what each movement is, by the source and the quantity it wrote.
        $read = fn (): array => array_map(fn (array $row): string => $this->stringAt($row, 'sourceType').' '.$this->stringAt($row, 'quantity'), $this->jsonList());

        foreach ([
            'kind[]=in&kind[]=adjustment' => ['count -2.000', 'receipt 5.000', 'receipt 10.000'],
            'kind=out' => ['loss -1.000', 'loss -1.000'],
            'sourceType[]=loss&sourceType[]=count' => ['loss -1.000', 'loss -1.000', 'count -2.000'],
            'reason[]=broken' => ['loss -1.000'],
            'reason[]=broken&reason[]=stolen' => ['loss -1.000', 'loss -1.000'],
            'costToComplete=yes' => ['receipt 5.000', 'receipt 10.000'],
            'costToComplete=no' => ['loss -1.000', 'loss -1.000', 'count -2.000'],
            'locationId[]='.$rack => ['loss -1.000', 'receipt 5.000'],
            // The site holds the rack, so picking it lists what moved on the rack too.
            'locationId[]='.$site.'&kind=in' => ['receipt 5.000', 'receipt 10.000'],
            'productId[]='.$this->laptopId.'&kind=in' => ['receipt 5.000', 'receipt 10.000'],
            'movedAt[from]='.$day('today') => ['loss -1.000', 'loss -1.000', 'count -2.000', 'receipt 5.000', 'receipt 10.000'],
            'movedAt[to]='.$day('-1 day') => [],
            'movedAt[from]='.$day('+1 day') => [],
            'movedAt[from]='.$day('today').'&movedAt[to]='.$day('today').'&locationId[]='.$rack.'&costToComplete=no' => ['loss -1.000'],
        ] as $query => $expected) {
            $this->getJson($this->path('stock-movements').'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($expected, $read(), $query);
            self::assertSame(\count($expected), $this->jsonPage()['totalItems'], $query);
        }

        foreach (['kind[]=sideways', 'reason[]=vanished', 'costToComplete=maybe', 'movedAt[from]=2026-02-30', 'locationId[]=not-an-id'] as $refused) {
            $this->getJson($this->path('stock-movements').'?'.$refused);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused);
        }
    }

    public function testTheStockListIsAPageSearchedNarrowedAndSortedByTheApi(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $site = $this->defaultLocationId();
        $this->postJson($this->path('stock-locations'), $this->location(['kind' => 'zone', 'code' => 'Z9', 'name' => 'Zone froide']));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $zone = $this->stringAt($this->json(), 'id');
        // A second product whose stock is kept: a service keeps none, so it can never be a row here.
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($unit);
        $mouse = Product::create($company, 'ART-002', new ProductDetails('Souris', null, ProductKind::Goods, '25'), $unit, null, [], new \DateTimeImmutable());
        $this->em()->persist($mouse);
        $this->em()->flush();

        foreach ([[$this->laptopId, $site, '10'], [$this->laptopId, $zone, '4'], [$mouse->getId()->toRfc4122(), $site, '7']] as [$product, $location, $quantity]) {
            $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $product, 'locationId' => $location, 'quantity' => $quantity]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }

        $this->getJson($this->path('stock-levels'));
        self::assertSame(3, $this->jsonPage()['totalItems'], 'one row per product and location something moved in');

        $this->getJson($this->path('stock-levels').'?itemsPerPage=2');
        self::assertCount(2, $this->jsonList());
        self::assertSame(3, $this->jsonPage()['totalItems']);

        $key = static function (array $row): string {
            self::assertIsString($row['productReference'] ?? null);
            self::assertIsString($row['locationCode'] ?? null);

            return $row['productReference'].'@'.$row['locationCode'];
        };
        foreach ([
            // The text finds the product and the location alike: both name a row a person is looking at.
            'q=portable' => ['ART-001@000', 'ART-001@Z9'],
            'q=ART-001' => ['ART-001@000', 'ART-001@Z9'],
            'q=froide' => ['ART-001@Z9'],
            'q=zzzz' => [],
            'locationId='.$zone => ['ART-001@Z9'],
            'establishmentId='.$this->establishmentId => ['ART-001@000', 'ART-001@Z9', 'ART-002@000'],
            'order[quantity]=desc&itemsPerPage=1' => ['ART-001@000'],
            'order[reference]=desc&order[location]=asc' => ['ART-002@000', 'ART-001@000', 'ART-001@Z9'],
        ] as $query => $rows) {
            $this->getJson($this->path('stock-levels').'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($rows, array_map($key, $this->jsonList()), $query);
        }

        $this->getJson($this->path('stock-levels').'?locationId=not-an-id');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * docs/SPEC.md § 7, 2026-10-06 00:15, row 197: the stock list combines its filters; a location stands for every
     * location under it and a product category for every category under it; « périmé » and the use-by day are read in
     * the company's own calendar.
     */
    public function testEveryFilterOfTheStockListCombines(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.read', 'product.write']);
        $site = $this->defaultLocationId();
        $this->postJson($this->path('stock-locations'), $this->location(['parentId' => $site, 'kind' => 'zone', 'code' => 'Z9', 'name' => 'Zone froide']));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $zone = $this->stringAt($this->json(), 'id');
        $company = $this->company();
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($unit);
        $now = new \DateTimeImmutable();
        $computing = ProductCategory::create($company, 'Informatique', null, $now);
        $accessories = ProductCategory::create($company, 'Accessoires', $computing, $now);
        $mouse = Product::create($company, 'ART-002', new ProductDetails('Souris', null, ProductKind::Goods, '25'), $unit, $accessories, [], $now);
        $cable = Product::create($company, 'ART-003', new ProductDetails('Câble', null, ProductKind::Goods, '5'), $unit, null, [], $now);
        foreach ([$computing, $accessories, $mouse, $cable] as $made) {
            $this->em()->persist($made);
        }
        $siteLocation = $this->em()->find(StockLocation::class, Uuid::fromString($site));
        self::assertInstanceOf(StockLocation::class, $siteLocation);
        // Goods that left before any arrived: the level falls below zero, as a delivery may make it.
        $this->em()->persist(StockMovement::delivery($cable, $siteLocation, '2', Uuid::v7(), $now));
        $this->em()->flush();
        $this->sendJson('PUT', '/api/companies/'.$company->getId()->toRfc4122().'/products/'.$this->laptopId, ['reference' => 'ART-001', 'name' => 'Portable 14"', 'description' => null, 'kind' => 'goods', 'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => '1250', 'costPrice' => null, 'categoryId' => null, 'defaultTaxComponentIds' => [], 'customFields' => [], 'isActive' => true, 'tracking' => 'lot']);
        self::assertResponseIsSuccessful();
        $today = new \DateTimeImmutable('today', new \DateTimeZone($company->getTimezone()));
        $day = static fn (string $shift): string => $today->modify($shift)->format('Y-m-d');
        foreach ([
            ['productId' => $this->laptopId, 'locationId' => $site, 'quantity' => '3', 'lotCode' => 'L-OLD', 'lotExpiresOn' => $day('-1 day')],
            ['productId' => $this->laptopId, 'locationId' => $zone, 'quantity' => '2', 'lotCode' => 'L-NEW', 'lotExpiresOn' => $day('+1 year')],
            ['productId' => $mouse->getId()->toRfc4122(), 'locationId' => $site, 'quantity' => '7'],
        ] as $receipt) {
            $this->postJson($this->path('stock-movements'), ['operation' => 'receive', ...$receipt]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        }
        $rows = function (): array {
            $keys = array_map(fn (array $row): string => $this->stringAt($row, 'productReference').'@'.$this->stringAt($row, 'locationCode').(\is_string($row['lotCode'] ?? null) ? '#'.$row['lotCode'] : ''), $this->jsonList());
            sort($keys);

            return $keys;
        };
        $old = 'ART-001@000#L-OLD';
        $new = 'ART-001@Z9#L-NEW';

        foreach ([
            'establishmentId[]='.$this->establishmentId => [$old, $new, 'ART-002@000', 'ART-003@000'],
            'negative=yes' => ['ART-003@000'],
            'negative=no' => [$old, $new, 'ART-002@000'],
            'expired=yes' => [$old],
            'expired=no' => [$new, 'ART-002@000', 'ART-003@000'],
            // The site holds the zone, so picking it lists what is on the zone too.
            'locationId[]='.$site => [$old, $new, 'ART-002@000', 'ART-003@000'],
            'locationId[]='.$zone => [$new],
            'productId[]='.$mouse->getId()->toRfc4122().'&productId[]='.$cable->getId()->toRfc4122() => ['ART-002@000', 'ART-003@000'],
            'categoryId[]='.$computing->getId()->toRfc4122() => ['ART-002@000'],
            'categoryId[]='.$accessories->getId()->toRfc4122() => ['ART-002@000'],
            'lotExpiresOn[to]='.$day('today') => [$old],
            'lotExpiresOn[from]='.$day('today') => [$new],
            'negative=no&expired=no&locationId[]='.$site => [$new, 'ART-002@000'],
        ] as $query => $expected) {
            $this->getJson($this->path('stock-levels').'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($expected, $rows(), $query);
            self::assertSame(\count($expected), $this->jsonPage()['totalItems'], $query);
        }

        foreach (['negative=maybe', 'expired=perhaps', 'lotExpiresOn[from]=2026-13-01', 'categoryId[]=not-an-id', 'establishmentId[]=not-an-id'] as $refused) {
            $this->getJson($this->path('stock-levels').'?'.$refused);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused);
        }
    }

    /** @return list<array<string, mixed>> */
    private function levels(): array
    {
        $this->getJson($this->path('stock-levels'));
        self::assertResponseIsSuccessful();

        return $this->jsonList();
    }

    /** @return list<list<mixed>> */
    private function locationRows(): array
    {
        return array_map(static fn (array $row) => [$row['parentId'], $row['establishmentId'], $row['kind'], $row['code'], $row['name'], $row['isDefault'], $row['childCount'], $row['movementCount']], $this->jsonList());
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function location(array $changes): array
    {
        return [...['establishmentId' => $this->establishmentId, 'parentId' => null, 'kind' => 'zone', 'code' => 'Z1', 'name' => 'Zone'], ...$changes];
    }

    private function path(string $resource, ?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource.(null === $id ? '' : '/'.$id);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('stock@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('stock@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}

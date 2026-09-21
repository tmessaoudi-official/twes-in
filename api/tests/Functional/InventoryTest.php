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
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\ModuleRegistry\Domain\ModuleState;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

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
        self::assertSame(['id', 'reference', 'name', 'unitCode', 'unitDecimals', 'homeLocationId'], array_keys($picks[0]));
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
        $laptop = ['reference' => 'ART-001', 'name' => 'Portable 14"', 'description' => null, 'kind' => 'goods', 'unitId' => $hour->getId()->toRfc4122(), 'unitPriceNet' => '1250', 'costPrice' => null, 'categoryId' => null, 'barcode' => null, 'defaultTaxComponentIds' => [], 'customFields' => [], 'isActive' => true];

        foreach (['unitId' => $laptop, 'kind' => [...$laptop, 'unitId' => $piece->getId()->toRfc4122(), 'kind' => 'service']] as $field => $body) {
            $this->sendJson('PUT', '/api/companies/'.$this->company->getId()->toRfc4122().'/products/'.$this->laptopId, $body);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
        }
        self::assertSame('C62', $this->em()->getConnection()->fetchOne('SELECT u.code FROM product p JOIN unit u ON u.id = p.unit_id WHERE p.id = ?', [$this->laptopId]));
        $this->sendJson('PUT', '/api/companies/'.$this->company->getId()->toRfc4122().'/products/'.$this->supportId, [...$laptop, 'reference' => 'SRV-001', 'name' => 'Assistance', 'kind' => 'service', 'unitPriceNet' => '50']);
        self::assertResponseIsSuccessful();
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

    /** A draft note delivering the laptop to the customer; its id. */
    private function draftNote(string $customerId, string $quantity): string
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
            'lines' => [['productId' => $this->laptopId, 'quantity' => $quantity]],
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

    private function defaultLocationId(): string
    {
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();

        return $this->stringAt($this->jsonList()[0], 'id');
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

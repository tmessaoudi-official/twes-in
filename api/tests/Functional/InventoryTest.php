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
        $this->em()->persist($laptop);
        $this->em()->persist($support);
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        $this->laptopId = $laptop->getId()->toRfc4122();
        $this->supportId = $support->getId()->toRfc4122();
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
        self::assertSame([[
            'productId' => $this->laptopId,
            'productReference' => 'ART-001',
            'productName' => 'Portable 14"',
            'unitCode' => 'C62',
            'locationId' => $siteId,
            'locationCode' => '000',
            'locationName' => 'Acme',
            'establishmentId' => $this->establishmentId,
            'quantity' => '8.000',
        ]], $this->jsonList());
        $this->getJson($this->path('stock-movements').'?productId='.$this->laptopId);
        self::assertSame(['adjustment', 'in'], array_column($this->jsonList(), 'kind'));

        $this->getJson($this->path('stock-options'));
        self::assertResponseIsSuccessful();
        $products = $this->arrayAt($this->json(), 'products');
        self::assertSame(['ART-001'], array_column($products, 'reference'), 'a service keeps no stock');
        self::assertSame([['C62'], [0]], [array_column($products, 'unitCode'), array_column($products, 'unitDecimals')]);
        self::assertSame(['000'], array_column($this->arrayAt($this->json(), 'establishments'), 'code'));

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

    private function defaultLocationId(): string
    {
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();

        return $this->stringAt($this->jsonList()[0], 'id');
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

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
use App\Module\Products\Domain\ValueObject\Barcode;
use App\Module\Products\Domain\ValueObject\BarcodeLine;
use App\Module\Products\Domain\ValueObject\BarcodeRole;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Products\Domain\ValueObject\ProductTracking;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class PurchaseOrdersTest extends ApiTestCase
{
    private const string ABSENT = '0192c3a4-0000-7000-8000-000000000000';
    private const string PASSWORD = 'password-1234';
    private const array WRITER = ['company.read', 'purchase.read', 'purchase.write'];
    private const array RECEIVER = ['company.read', 'purchase.read', 'purchase.receive'];
    private const array BUYER = ['company.read', 'purchase.read', 'purchase.write', 'purchase.receive', 'product.cost.read'];

    private Company $company;
    private string $vendor;
    private string $screws;
    private string $paint;
    private string $fitting;
    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme', named: false);
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $screws = Product::create($this->company, 'VIS-6', new ProductDetails('Vis 6 mm', null, ProductKind::Goods, '0.500'), $piece, null, [], $now);
        $screws->reviseCost('0.250', $now);
        $paint = Product::create($this->company, 'PEINT-1', new ProductDetails('Peinture blanche', null, ProductKind::Goods, '30'), $piece, null, [], $now);
        $paint->track(ProductTracking::Lot, $now);
        $fitting = Product::create($this->company, 'POSE', new ProductDetails('Pose', null, ProductKind::Service, '40'), $piece, null, [], $now);
        $vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Quincaillerie du Sud'), $now);
        foreach ([$screws, $paint, $fitting, $vendor] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        [$this->screws, $this->paint, $this->fitting] = array_map(static fn (Product $p): string => $p->getId()->toRfc4122(), [$screws, $paint, $fitting]);
        $this->vendor = $vendor->getId()->toRfc4122();

        $this->createUser('owner@twes.local', self::PASSWORD, $this->company, ['*'], 'owner');
        $this->login('owner@twes.local', self::PASSWORD);
        $this->getJson($this->companyPath().'/stock-locations');
        self::assertResponseIsSuccessful();
        $this->site = $this->stringAt($this->jsonList()[0], 'id');
    }

    public function testAWriterDraftsAnOrderWhoseLinesStartFromTheirProductsAndSeesNoPrice(): void
    {
        $this->signedIn('writer', self::WRITER);

        $this->postJson($this->path(), ['vendorId' => $this->vendor, 'expectedOn' => '2026-11-02', 'lines' => [
            ['productId' => $this->screws, 'quantity' => '500', 'unitPriceNet' => '9'],
        ]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $order = $this->json();
        self::assertSame(['draft', null, null, null], [$order['status'], $order['number'], $order['issueDate'], $order['vendorSnapshot']]);
        self::assertSame([$this->vendor, 'Quincaillerie du Sud', '2026-11-02'], [$order['vendorId'], $order['vendorName'], $order['expectedOn']]);
        $line = $this->arrayAt($order, 'lines')[0];
        self::assertIsArray($line);
        self::assertSame(['VIS-6', 'Vis 6 mm', '500.000', 'none'], [$line['productReference'], $line['description'], $line['quantity'], $line['productTracking']]);
        self::assertSame(['0.000', '500.000'], [$line['received'], $line['outstanding']]);
        // A price is a cost: one sent by whoever may not read costs is not kept, and none is told back.
        self::assertSame([null, null, null], [$line['unitPriceNet'], $line['net'], $order['totalNet']]);
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT unit_price_net FROM purchase_order_line'));
        self::assertSame('[]', $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'purchase_order.created'"));

        // An order a cost reader priced is read without its prices, in the list as on its own.
        $this->signedIn('buyer', self::BUYER);
        $priced = $this->draft();
        $this->signedIn('writer', self::WRITER);
        foreach ([$this->path($priced), $this->path().'?q=sud'] as $read) {
            $this->getJson($read);
            self::assertResponseIsSuccessful();
            foreach (str_contains($read, '?') ? $this->jsonList() : [$this->json()] as $row) {
                self::assertNull($row['totalNet'], $read);
                self::assertSame([], array_filter(array_column($this->rows($row, 'lines'), 'unitPriceNet'), static fn (mixed $price): bool => null !== $price), $read);
            }
        }
    }

    public function testACostReaderSeesTheProductsCostAsTheLinesPriceAndTheNetAmounts(): void
    {
        $this->signedIn('buyer', self::BUYER);

        $this->postJson($this->path(), ['vendorId' => $this->vendor, 'lines' => [
            ['productId' => $this->screws, 'quantity' => '500'],
            ['productId' => $this->screws, 'quantity' => '3', 'unitPriceNet' => '0.3333'],
        ]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $order = $this->json();
        $lines = $this->arrayAt($order, 'lines');
        self::assertSame(['0.2500', '0.3333'], array_column($lines, 'unitPriceNet'));
        self::assertSame(['125.000', '1.000'], array_column($lines, 'net'));
        self::assertSame('126.000', $order['totalNet']);
        // What the screen writes a price at, as the printed order does: the dinar's three decimals, more only when a
        // price has them.
        self::assertSame(3, $order['currencyScale']);
    }

    public function testWhatTheOrderRefusesAnswersUnprocessableNamingTheField(): void
    {
        $this->signedIn('writer', self::WRITER);
        $screws = ['productId' => $this->screws, 'quantity' => '1'];

        foreach ([
            ['vendorId', ['vendorId' => self::ABSENT, 'lines' => [$screws]]],
            ['establishmentId', ['vendorId' => $this->vendor, 'establishmentId' => self::ABSENT, 'lines' => [$screws]]],
            ['lines[0].quantity', ['vendorId' => $this->vendor, 'lines' => [[...$screws, 'quantity' => '1.5']]]],
            ['lines[0].quantity', ['vendorId' => $this->vendor, 'lines' => [[...$screws, 'quantity' => '0']]]],
            ['lines[1].productId', ['vendorId' => $this->vendor, 'lines' => [$screws, ['productId' => self::ABSENT, 'quantity' => '1']]]],
            ['lines[0].productId', ['vendorId' => $this->vendor, 'lines' => [['productId' => $this->fitting, 'quantity' => '1']]]],
        ] as [$field, $body]) {
            $this->postJson($this->path(), $body);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringStartsWith($field.': ', $this->stringAt($this->json(), 'detail'), $field);
            self::assertContains($field, array_column($this->refusals(), 'propertyPath'), $field.': the refusal names its field as a violation, which the screen reads');
        }
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM purchase_order'));
    }

    public function testSendingNumbersTheOrderKeepsTheVendorAsItWasAndFixesIt(): void
    {
        $this->signedIn('writer', self::WRITER);
        $this->postJson($this->path(), ['vendorId' => $this->vendor, 'lines' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $empty = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path($empty).'/send', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringStartsWith('lines: ', $this->stringAt($this->json(), 'detail'));

        $id = $this->draft();
        $this->getJson($this->path($id));
        $version = $this->intAt($this->json(), 'version');
        $this->sendJson('PUT', $this->path($id), ['vendorId' => $this->vendor, 'version' => $version, 'remarksPrinted' => 'Livrer le matin', 'lines' => [['productId' => $this->screws, 'quantity' => '12']]]);
        self::assertResponseIsSuccessful();
        self::assertSame('12.000', $this->rows($this->json(), 'lines')[0]['quantity'] ?? null);
        self::assertSame(['fields' => ['remarksPrinted', 'lines']], json_decode($this->text("SELECT changes::text FROM audit_log WHERE action = 'purchase_order.revised'"), true));
        $this->sendJson('PUT', $this->path($id), ['vendorId' => $this->vendor, 'version' => $version, 'lines' => [['productId' => $this->screws, 'quantity' => '13']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a revision opened before the last one is refused');

        $this->postJson($this->path($id).'/send', null);

        self::assertResponseIsSuccessful();
        $order = $this->json();
        self::assertSame('sent', $order['status']);
        self::assertMatchesRegularExpression('/^BCF-\d{4}-\d{2}-00001$/', $this->stringAt($order, 'number'));
        self::assertNotNull($order['issueDate']);
        self::assertSame(['FRN-0001', 'Quincaillerie du Sud'], [$this->section($order, 'vendorSnapshot')['number'], $this->section($order, 'vendorSnapshot')['name']]);
        $this->sendJson('PUT', $this->path($id), ['vendorId' => $this->vendor, 'version' => $this->intAt($order, 'version'), 'lines' => [['productId' => $this->screws, 'quantity' => '1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a sent order is the vendor\'s now');
        $this->postJson($this->path($id).'/send', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testGoodsReceivedInPartsGoOnTheShelvesNamingTheirReceiptAndFinishTheOrder(): void
    {
        $this->signedIn('buyer', self::BUYER);
        $id = $this->sent([['productId' => $this->screws, 'quantity' => '10', 'unitPriceNet' => '0.2000']]);
        $line = $this->lineIds($id)[0];

        $this->postJson($this->path($id).'/receipts', ['receivedOn' => $this->today(), 'supplierReference' => 'BL-778', 'lines' => [
            ['orderLineId' => $line, 'locationId' => $this->site, 'quantity' => '4'],
        ]]);

        self::assertResponseIsSuccessful();
        $order = $this->json();
        self::assertSame('partly_received', $order['status']);
        self::assertSame(['4.000', '6.000'], [$this->rows($order, 'lines')[0]['received'] ?? null, $this->rows($order, 'lines')[0]['outstanding'] ?? null]);
        $receipts = $this->rows($order, 'receipts');
        self::assertCount(1, $receipts);
        self::assertSame(['BL-778', $this->today()], [$receipts[0]['supplierReference'] ?? null, $receipts[0]['receivedOn'] ?? null]);

        $this->postJson($this->path($id).'/receipts', ['receivedOn' => $this->today(), 'lines' => [
            ['orderLineId' => $line, 'locationId' => $this->site, 'quantity' => '6', 'unitCost' => '0.2600'],
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSame('received', $this->json()['status']);
        $movements = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT m.quantity, m.source_type, m.unit_cost, m.supplier_reference, m.vendor_id::text AS vendor, l.id::text AS receipt_line FROM stock_movement m LEFT JOIN goods_receipt_line l ON l.id = m.source_id ORDER BY m.at, m.quantity',
        );
        self::assertSame([
            ['quantity' => '4.000', 'source_type' => 'receipt', 'unit_cost' => '0.2000', 'supplier_reference' => 'BL-778', 'vendor' => $this->vendor],
            ['quantity' => '6.000', 'source_type' => 'receipt', 'unit_cost' => '0.2600', 'supplier_reference' => null, 'vendor' => $this->vendor],
        ], array_map(static fn (array $row): array => array_diff_key($row, ['receipt_line' => true]), $movements));
        self::assertNotContains(null, array_column($movements, 'receipt_line'), 'each movement names the receipt line it came from');
        self::assertSame(['purchase_order.received', 'purchase_order.received'], $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE action = 'purchase_order.received'"));

        $this->postJson($this->path($id).'/receipts', ['receivedOn' => $this->today(), 'lines' => [['orderLineId' => $line, 'locationId' => $this->site, 'quantity' => '1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a received order takes no more goods');
    }

    public function testAReceiptBringsNoMoreThanIsExpectedAndARefusedOneMovesNothing(): void
    {
        $this->signedIn('buyer', self::BUYER);
        $id = $this->sent([['productId' => $this->screws, 'quantity' => '10'], ['productId' => $this->paint, 'quantity' => '2']]);
        [$screws, $paint] = $this->lineIds($id);

        foreach ([
            ['lines[1].quantity', [['orderLineId' => $screws, 'locationId' => $this->site, 'quantity' => '4'], ['orderLineId' => $screws, 'locationId' => $this->site, 'quantity' => '7']]],
            ['lines[0].orderLineId', [['orderLineId' => self::ABSENT, 'locationId' => $this->site, 'quantity' => '1']]],
            ['lines[0].locationId', [['orderLineId' => $screws, 'locationId' => self::ABSENT, 'quantity' => '1']]],
            ['lines[1].lotCode', [['orderLineId' => $screws, 'locationId' => $this->site, 'quantity' => '1'], ['orderLineId' => $paint, 'locationId' => $this->site, 'quantity' => '1']]],
            ['lines[0].lotExpiresOn', [['orderLineId' => $paint, 'locationId' => $this->site, 'quantity' => '1', 'lotExpiresOn' => '2027-01-01']]],
        ] as [$field, $lines]) {
            $this->postJson($this->path($id).'/receipts', ['receivedOn' => $this->today(), 'lines' => $lines]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringStartsWith($field.': ', $this->stringAt($this->json(), 'detail'), $field);
        }
        $this->postJson($this->path($id).'/receipts', ['receivedOn' => '2099-01-01', 'lines' => [['orderLineId' => $screws, 'locationId' => $this->site, 'quantity' => '1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'goods do not arrive tomorrow');

        $connection = $this->em()->getConnection();
        self::assertEquals([0, 0], [$connection->fetchOne('SELECT count(*) FROM stock_movement'), $connection->fetchOne('SELECT count(*) FROM goods_receipt')]);
        self::assertSame(['0.000', '0.000'], $connection->fetchFirstColumn('SELECT received FROM purchase_order_line ORDER BY position'));

        $this->postJson($this->path($id).'/receipts', ['receivedOn' => $this->today(), 'lines' => [['orderLineId' => $paint, 'locationId' => $this->site, 'quantity' => '2', 'lotCode' => 'L-2611', 'lotExpiresOn' => '2028-06-30']]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['L-2611', '2028-06-30'], array_values($connection->fetchAssociative('SELECT l.code, l.expires_on::text FROM stock_movement m JOIN stock_lot l ON l.id = m.lot_id') ?: []));
    }

    public function testWhoeverMayNotReadCostsReceivesAtTheOrdersPriceAndLeavesTheRestToComplete(): void
    {
        $this->signedIn('buyer', self::BUYER);
        $id = $this->sent([['productId' => $this->screws, 'quantity' => '10', 'unitPriceNet' => '0.2000'], ['productId' => $this->paint, 'quantity' => '1']]);
        [$screws, $paint] = $this->lineIds($id);
        $this->em()->getConnection()->executeStatement('UPDATE purchase_order_line SET unit_price_net = NULL WHERE id = ?', [$paint]);
        $this->signedIn('clerk', self::RECEIVER);

        $this->postJson($this->path($id).'/receipts', ['receivedOn' => $this->today(), 'apply' => 'last', 'lines' => [
            ['orderLineId' => $screws, 'locationId' => $this->site, 'quantity' => '10', 'unitCost' => '9'],
            ['orderLineId' => $paint, 'locationId' => $this->site, 'quantity' => '1', 'lotCode' => 'L-1'],
        ]]);

        self::assertResponseIsSuccessful();
        $order = $this->json();
        self::assertSame([null, null], array_column($this->arrayAt($order, 'lines'), 'unitPriceNet'));
        self::assertSame([null, null], array_column($this->rows($this->rows($order, 'receipts')[0] ?? [], 'lines'), 'unitCost'));
        self::assertSame(
            [['unit_cost' => '0.2000', 'cost_to_complete' => false], ['unit_cost' => null, 'cost_to_complete' => true]],
            $this->em()->getConnection()->fetchAllAssociative('SELECT unit_cost, cost_to_complete FROM stock_movement ORDER BY quantity DESC'),
        );
        self::assertSame('0.2500', $this->em()->getConnection()->fetchOne('SELECT cost_price FROM product WHERE id = ?', [$this->screws]), 'whoever may not read costs moves no cost');
    }

    public function testALineKeepsTheVendorsOwnCodeForItsProductAndOnlyACostReaderReadsIt(): void
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $other = Vendor::create($company, 'FRN-0002', new VendorProfile('Outillage du Nord'), new \DateTimeImmutable());
        $this->em()->persist($other);
        $this->codes([
            new BarcodeLine(BarcodeRole::Unit, new Barcode('6191234567897'), 1),
            // A carton's code is not the code of what a line counts.
            new BarcodeLine(BarcodeRole::Supplier, new Barcode('SUD-C100'), 100, $this->vendorEntity($this->vendor)),
            new BarcodeLine(BarcodeRole::Supplier, new Barcode('SUD-VIS6'), 1, $this->vendorEntity($this->vendor)),
            new BarcodeLine(BarcodeRole::Supplier, new Barcode('NORD-61'), 1, $other),
        ]);
        $otherId = $other->getId()->toRfc4122();

        $this->signedIn('buyer', self::BUYER);
        $this->postJson($this->path(), ['vendorId' => $this->vendor, 'lines' => [
            ['productId' => $this->screws, 'quantity' => '10'],
            ['productId' => $this->paint, 'quantity' => '2'],
        ]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        self::assertSame(['SUD-VIS6', null], array_column($this->rows($this->json(), 'lines'), 'vendorCode'));

        // Another vendor named by the next save brings its own code, and the lines did not change for it.
        $this->sendJson('PUT', $this->path($id), ['vendorId' => $otherId, 'version' => $this->intAt($this->json(), 'version'), 'lines' => [
            ['productId' => $this->screws, 'quantity' => '10', 'unitPriceNet' => '0.2500'],
            ['productId' => $this->paint, 'quantity' => '2'],
        ]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['NORD-61', null], array_column($this->rows($this->json(), 'lines'), 'vendorCode'));
        self::assertSame(['fields' => ['vendorId']], json_decode($this->text("SELECT changes::text FROM audit_log WHERE action = 'purchase_order.revised'"), true));

        // What the vendor's code is when the order goes out is what the order keeps.
        $this->codes([new BarcodeLine(BarcodeRole::Supplier, new Barcode('NORD-62'), 1, $this->vendorEntity($otherId))]);
        $this->postJson($this->path($id).'/send', null);
        self::assertResponseIsSuccessful();
        self::assertSame(['NORD-62', null], array_column($this->rows($this->json(), 'lines'), 'vendorCode'));
        $this->codes([new BarcodeLine(BarcodeRole::Supplier, new Barcode('NORD-63'), 1, $this->vendorEntity($otherId))]);
        $this->getJson($this->path($id));
        self::assertSame(['NORD-62', null], array_column($this->rows($this->json(), 'lines'), 'vendorCode'));

        // A supplier's code is a cost reader's to read, in the order as on the product.
        $this->signedIn('writer', self::WRITER);
        foreach ([$this->path($id), $this->path().'?q=nord'] as $read) {
            $this->getJson($read);
            self::assertResponseIsSuccessful();
            foreach (str_contains($read, '?') ? $this->jsonList() : [$this->json()] as $row) {
                self::assertSame([null, null], array_column($this->rows($row, 'lines'), 'vendorCode'), $read);
            }
        }
        self::assertSame('NORD-62', $this->text('SELECT vendor_code FROM purchase_order_line WHERE vendor_code IS NOT NULL'));
    }

    public function testAnOrderIsClosedShortOrCancelledWhileNothingCame(): void
    {
        $this->signedIn('buyer', self::BUYER);
        $draft = $this->draft();
        $this->postJson($this->path($draft).'/cancel', null);
        self::assertResponseIsSuccessful();
        self::assertSame('cancelled', $this->json()['status']);

        $id = $this->sent([['productId' => $this->screws, 'quantity' => '10']]);
        $this->postJson($this->path($id).'/receipts', ['receivedOn' => $this->today(), 'lines' => [['orderLineId' => $this->lineIds($id)[0], 'locationId' => $this->site, 'quantity' => '3']]]);
        self::assertResponseIsSuccessful();
        $this->postJson($this->path($id).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'goods came: the order is closed, not cancelled');

        $this->postJson($this->path($id).'/close', null);

        self::assertResponseIsSuccessful();
        self::assertSame(['closed', '7.000'], [$this->json()['status'], $this->rows($this->json(), 'lines')[0]['outstanding'] ?? null]);
        $this->postJson($this->path($id).'/receipts', ['receivedOn' => $this->today(), 'lines' => [['orderLineId' => $this->lineIds($id)[0], 'locationId' => $this->site, 'quantity' => '1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['purchase_order.cancelled', 'purchase_order.closed'], $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE action IN ('purchase_order.cancelled', 'purchase_order.closed') ORDER BY action"));
    }

    public function testEachMoveTakesItsOwnPermissionAndAnotherCompanysOrderIsNotThere(): void
    {
        $this->signedIn('buyer', self::BUYER);
        $id = $this->sent([['productId' => $this->screws, 'quantity' => '10']]);
        $line = $this->lineIds($id)[0];

        $this->signedIn('reader', ['company.read']);
        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'reading needs purchase.read');
        $this->signedIn('writer', self::WRITER);
        $this->postJson($this->path($id).'/receipts', ['receivedOn' => $this->today(), 'lines' => [['orderLineId' => $line, 'locationId' => $this->site, 'quantity' => '1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'receiving needs purchase.receive');
        $this->signedIn('clerk', self::RECEIVER);
        $this->postJson($this->path($id).'/close', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'closing needs purchase.write');
        $this->postJson($this->path(), ['vendorId' => $this->vendor, 'lines' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'drafting needs purchase.write');

        $globex = $this->createCompany('Globex', named: false);
        static::getContainer()->get(ProvisionCompany::class)->handle($globex);
        $this->createUser('globex@twes.local', self::PASSWORD, $globex, ['*'], 'owner');
        $this->login('globex@twes.local', self::PASSWORD);
        $this->getJson('/api/companies/'.$globex->getId()->toRfc4122().'/purchase-orders/'.$id);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company\'s order is not there');
        $this->getJson($this->path($id));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'nor is a company one does not belong to');
    }

    /**
     * The printed order carries its prices, which are costs: a cost reader downloads it, across a watermark while it is a
     * draft and as it was sent once sent; anyone else is answered as if there were no such order.
     */
    public function testACostReaderPrintsTheOrderWithItsPricesAndNobodyElseFindsIt(): void
    {
        $this->signedIn('buyer', self::BUYER);
        $id = $this->draft();

        $this->client->request('GET', $this->path($id).'/pdf');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('content-type'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store') && $response->headers->hasCacheControlDirective('private'), 'a priced order stays in no cache');
        self::assertStringContainsString('purchase-order-'.$id.'.pdf', (string) $response->headers->get('content-disposition'));
        $draft = (string) $response->getContent();
        self::assertStringStartsWith('%PDF-', $draft);
        foreach (['Bon de commande', 'BROUILLON', 'Quincaillerie du Sud', 'FRN-0001', 'VIS-6', '0,250', '2,500'] as $expected) {
            self::assertStringContainsString($expected, $draft);
        }
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM file'), 'an order is never stored');

        $this->postJson($this->path($id).'/send', null);
        self::assertResponseIsSuccessful();
        $number = $this->stringAt($this->json(), 'number');
        $this->client->request('GET', $this->path($id).'/pdf');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($number.'.pdf', (string) $this->client->getResponse()->headers->get('content-disposition'));
        $sent = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($number, $sent);
        self::assertStringNotContainsString('BROUILLON', $sent);

        $this->signedIn('writer', self::WRITER);
        $this->client->request('GET', $this->path($id).'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'whoever may not read costs reads no printed price');
        $this->signedIn('buyer', self::BUYER);
        $this->client->request('GET', $this->path(self::ABSENT).'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $globex = $this->createCompany('Globex', named: false);
        static::getContainer()->get(ProvisionCompany::class)->handle($globex);
        $this->createUser('globex@twes.local', self::PASSWORD, $globex, ['*'], 'owner');
        $this->login('globex@twes.local', self::PASSWORD);
        $this->client->request('GET', '/api/companies/'.$globex->getId()->toRfc4122().'/purchase-orders/'.$id.'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company\'s order is not there');
        $this->client->request('GET', $this->path($id).'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'nor is a company one does not belong to');

        $this->sendJson('POST', '/api/auth/logout');
        $this->client->request('GET', $this->path($id).'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testASwitchedOffModuleAnswersNotFound(): void
    {
        $this->login('owner@twes.local', self::PASSWORD);
        $this->sendJson('PUT', $this->companyPath().'/modules/purchases', ['enabled' => false]);
        self::assertResponseIsSuccessful();

        $this->getJson($this->path());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->companyPath().'/purchase-order-options/vendors');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->client->request('GET', $this->path(self::ABSENT).'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the printed order is the module\'s too');
    }

    public function testTheListSearchesTheVendorAndCountsEachStatus(): void
    {
        $now = new \DateTimeImmutable();
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $other = Vendor::create($company, 'FRN-0002', new VendorProfile('Électricité Générale'), $now);
        $this->em()->persist($other);
        $this->em()->flush();
        $this->signedIn('writer', self::WRITER);
        $this->draft();
        $sent = $this->sent([['productId' => $this->screws, 'quantity' => '1']]);
        $this->postJson($this->path(), ['vendorId' => $other->getId()->toRfc4122(), 'lines' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->getJson($this->path().'?q=electricite');
        self::assertSame(['Électricité Générale'], array_column($this->jsonList(), 'vendorName'));
        $this->getJson($this->path().'?status[]=sent');
        self::assertSame([$sent], array_column($this->jsonList(), 'id'));
        $this->getJson($this->path().'?vendorId[]='.$this->vendor.'&order[number]=asc');
        self::assertSame(['sent', 'draft'], array_column($this->jsonList(), 'status'), 'drafts carry no number and come last');

        $this->getJson($this->companyPath().'/purchase-order-status-counts');
        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->json()['all']);
        self::assertSame(['draft' => 2, 'sent' => 1, 'partly_received' => 0, 'received' => 0, 'closed' => 0, 'cancelled' => 0], $this->json()['statuses']);
    }

    public function testThePickersOfferGoodsAndTellTheCostOnlyToWhoeverMayReadIt(): void
    {
        $this->signedIn('writer', self::WRITER);
        $this->getJson($this->companyPath().'/purchase-order-options/products');
        self::assertResponseIsSuccessful();
        $offered = array_column($this->jsonList(), null, 'reference');
        self::assertSame(['PEINT-1', 'VIS-6'], array_keys($offered), 'a service is not ordered from a vendor');
        self::assertArrayHasKey('costPrice', $offered['VIS-6']);
        self::assertSame([null, 'lot'], [$offered['VIS-6']['costPrice'], $offered['PEINT-1']['tracking'] ?? null]);
        self::assertSame([true, true], array_column($offered, 'goods'));
        // Asked by id, a service is told for what it is, so a screen adding it to an order leaves it out.
        $this->getJson($this->companyPath().'/purchase-order-options/products?ids[]='.$this->fitting.'&ids[]='.$this->screws);
        $goods = array_column($this->jsonList(), 'goods', 'reference');
        ksort($goods);
        self::assertSame(['POSE' => false, 'VIS-6' => true], $goods);
        // A new line says what its quantity counts before it is saved.
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertSame($piece?->getName(), $offered['VIS-6']['unitName'] ?? null);
        $this->getJson($this->companyPath().'/purchase-order-options/vendors?q=sud');
        self::assertSame(['FRN-0001'], array_column($this->jsonList(), 'number'));

        $this->signedIn('buyer', self::BUYER);
        $this->getJson($this->companyPath().'/purchase-order-options/products?q=vis');
        self::assertSame(['0.2500'], array_column($this->jsonList(), 'costPrice'));
    }

    public function testAReceiverIsOfferedTheOrdersPlacesWithEachLineAtItsProductsHome(): void
    {
        $this->postJson($this->companyPath().'/stock-locations', ['establishmentId' => $this->establishment(), 'parentId' => $this->site, 'kind' => 'rack', 'code' => 'R1', 'name' => 'Rayon 1']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $rack = $this->stringAt($this->json(), 'id');
        static::getContainer()->get(KeepProductHomes::class)->set($this->company, Uuid::fromString($this->screws), Uuid::fromString($rack), null);
        $id = $this->sent([['productId' => $this->screws, 'quantity' => '10'], ['productId' => $this->paint, 'quantity' => '2']]);
        self::assertSame(['C62', 'C62'], array_column($this->rows($this->json(), 'lines'), 'unitCode'), 'a line says the unit it counts in');
        [$screws, $paint] = $this->lineIds($id);

        $this->signedIn('receiver', self::RECEIVER);
        $this->getJson($this->path($id).'/receiving');

        self::assertResponseIsSuccessful();
        $receiving = $this->json();
        self::assertSame(
            [[$this->site, null, true], [$rack, $this->site, false]],
            array_map(static fn (array $place): array => [$place['id'] ?? null, $place['parentId'] ?? null, $place['isDefault'] ?? null], $this->rows($receiving, 'places')),
        );
        self::assertSame(['R1', 'Rayon 1'], [$this->rows($receiving, 'places')[1]['code'] ?? null, $this->rows($receiving, 'places')[1]['name'] ?? null]);
        self::assertSame(
            [['orderLineId' => $screws, 'locationId' => $rack], ['orderLineId' => $paint, 'locationId' => $this->site]],
            $this->rows($receiving, 'homes'),
            'a line arrives at its product\'s home, else at the establishment\'s own place',
        );
        self::assertTrue($receiving['asksCostBasis'] ?? null, 'the company leaves what the cost becomes to whoever receives');

        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $this->em()->persist(new Setting(SettingAddress::company($company), 'stock.cost_on_receive', 'average', new \DateTimeImmutable()));
        $this->em()->flush();
        $this->getJson($this->path($id).'/receiving');
        self::assertFalse($this->json()['asksCostBasis'] ?? null, 'the company decides, so nobody receiving is asked');

        $this->signedIn('writer', self::WRITER);
        $this->getJson($this->path($id).'/receiving');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'only whoever may receive is told where goods go');
        $this->signedIn('receiver', self::RECEIVER);
        $this->getJson($this->path(self::ABSENT).'/receiving');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAShopWhoseStockWasNeverOpenedIsOfferedItsOwnPlaceToReceiveInto(): void
    {
        // A company's first receipt can come before anyone opened its stock pages, which make the default place: the
        // receiver is offered it all the same, or the dialog offers nothing and refuses every line.
        $id = $this->sent([['productId' => $this->screws, 'quantity' => '10']]);
        $this->em()->getConnection()->executeStatement('DELETE FROM stock_location WHERE company_id = ?', [$this->company->getId()->toRfc4122()]);

        $this->signedIn('receiver', self::RECEIVER);
        $this->getJson($this->path($id).'/receiving');

        self::assertResponseIsSuccessful();
        $places = $this->rows($this->json(), 'places');
        self::assertSame([[null, true]], array_map(static fn (array $place): array => [$place['parentId'] ?? null, $place['isDefault'] ?? null], $places));
        self::assertSame([$places[0]['id'] ?? null], array_column($this->rows($this->json(), 'homes'), 'locationId'), 'the line arrives at that place');
    }

    /** @param list<BarcodeLine> $lines the screws' codes from now on */
    private function codes(array $lines): void
    {
        $screws = $this->em()->find(Product::class, Uuid::fromString($this->screws));
        self::assertNotNull($screws);
        $screws->replaceBarcodes($lines, new \DateTimeImmutable());
        $this->em()->flush();
    }

    /** A vendor as the entity manager holds it now: a request reboots the kernel and detaches what was read before. */
    private function vendorEntity(string $id): Vendor
    {
        $vendor = $this->em()->find(Vendor::class, Uuid::fromString($id));
        self::assertNotNull($vendor);

        return $vendor;
    }

    /** @param list<string> $permissions */
    private function signedIn(string $name, array $permissions): void
    {
        $email = $name.'@twes.local';
        if (false === $this->em()->getConnection()->fetchOne('SELECT 1 FROM "user" WHERE email = ?', [$email])) {
            $company = $this->em()->find(Company::class, $this->company->getId());
            self::assertNotNull($company);
            $this->createUser($email, self::PASSWORD, $company, $permissions, $name);
        }
        $this->login($email, self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    private function establishment(): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT id::text FROM establishment WHERE company_id = ? AND is_default', [$this->company->getId()]);
        self::assertIsString($id);

        return $id;
    }

    private function draft(): string
    {
        $this->postJson($this->path(), ['vendorId' => $this->vendor, 'lines' => [['productId' => $this->screws, 'quantity' => '10']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /**
     * An order not yet sent is deleted freely with its lines (docs/SPEC.md § 7, row 76), named by its vendor since it has
     * no number yet, and « Restaurer » puts every row back; a sent order is what the vendor holds, never a draft.
     */
    public function testADraftOrderGoesWithItsLinesAndComesBackWhileASentOneStays(): void
    {
        $this->signedIn('writer', self::WRITER);
        $draft = $this->draft();
        $rows = fn (): array => [
            $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM purchase_order WHERE id = ?', [$draft]),
            $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM purchase_order_line WHERE purchase_order_id = ? ORDER BY position', [$draft]),
        ];
        $before = $rows();
        self::assertCount(1, $before[1]);

        $this->postJson($this->companyPath().'/record-removals', ['subject' => 'purchase_order', 'ids' => [$draft]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $removal = $this->json();
        self::assertSame(['purchase_order', [['id' => $draft, 'label' => 'FRN-0001 Quincaillerie du Sud']]], [$removal['subject'], $removal['items']]);
        self::assertSame([[], []], $rows(), 'nothing of the draft is left');
        $this->postJson($this->companyPath().'/record-removals/'.$this->stringAt($removal, 'id').'/restore', null);
        self::assertResponseIsSuccessful();
        self::assertSame($before, $rows());

        $sent = $this->sent([['productId' => $this->screws, 'quantity' => '10']]);
        $this->postJson($this->companyPath().'/record-removals', ['subject' => 'purchase_order', 'ids' => [$sent]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a sent order is no draft, so it answers as a stranger\'s would');
        self::assertEquals(1, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM purchase_order WHERE id = ?', [$sent]));
    }

    /** @param list<array<string, string>> $lines */
    private function sent(array $lines): string
    {
        $this->postJson($this->path(), ['vendorId' => $this->vendor, 'lines' => $lines]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path($id).'/send', null);
        self::assertResponseIsSuccessful();

        return $id;
    }

    /** @return list<string> */
    private function lineIds(string $orderId): array
    {
        $ids = $this->em()->getConnection()->fetchFirstColumn('SELECT id::text FROM purchase_order_line WHERE purchase_order_id = ? ORDER BY position', [$orderId]);

        return array_map(static fn (mixed $id): string => \is_string($id) ? $id : self::fail('an id reads as text'), $ids);
    }

    /**
     * The objects listed under `$key`, each asserted to be one: PHPStan runs at max here.
     *
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $body, string $key): array
    {
        $rows = [];
        foreach ($this->arrayAt($body, $key) as $row) {
            self::assertIsArray($row, "$key holds objects");
            $rows[] = $this->section(['row' => $row], 'row');
        }

        return $rows;
    }

    private function text(string $sql): string
    {
        $value = $this->em()->getConnection()->fetchOne($sql);
        self::assertIsString($value, $sql);

        return $value;
    }

    private function today(): string
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Africa/Tunis'))->format('Y-m-d');
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function path(?string $id = null): string
    {
        return $this->companyPath().'/purchase-orders'.(null === $id ? '' : '/'.$id);
    }
}

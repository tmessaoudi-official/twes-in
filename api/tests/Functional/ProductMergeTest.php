<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\Inventory\Domain\Exception\InvalidStockMovement;
use App\Module\Inventory\Domain\Model\StockLocation;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Domain\Repository\StockMovementRepository;
use App\Module\Products\Domain\Model\Product;
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

/**
 * Products merged into one stay, switched off, pointing at the product kept: the drafts selling or ordering them, their
 * codes, the vendors they are bought from and their prices in a list move to it, while an issued document keeps the
 * product it names. « Annuler la fusion » puts everything back, until the product kept has been sold since.
 */
final class ProductMergeTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    private Company $company;

    /** @var array<string, string> product id by reference */
    private array $products = [];

    private string $customerId;

    private string $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $now = new \DateTimeImmutable();
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->aProduct('VIS-6', 'C62', ProductKind::Goods, ProductTracking::None, $now);
        $this->aProduct('VIS-6B', 'C62', ProductKind::Goods, ProductTracking::None, $now);
        $this->aProduct('VIS-6C', 'C62', ProductKind::Goods, ProductTracking::None, $now);
        $this->aProduct('VIS-P', 'H87', ProductKind::Goods, ProductTracking::None, $now);
        $this->aProduct('POSE', 'C62', ProductKind::Service, ProductTracking::None, $now);
        $this->aProduct('VIS-L', 'C62', ProductKind::Goods, ProductTracking::Lot, $now);
        $this->aProduct('VIS-L2', 'C62', ProductKind::Goods, ProductTracking::Lot, $now);
        $this->aProduct('PC-S', 'C62', ProductKind::Goods, ProductTracking::Serial, $now);
        $this->aProduct('PC-S2', 'C62', ProductKind::Goods, ProductTracking::Serial, $now);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], $now);
        $this->em()->persist($customer);
        $this->customerId = $customer->getId()->toRfc4122();
        $vendor = Vendor::create($this->company, 'F-001', new VendorProfile('Sotumag'), $now);
        $this->em()->persist($vendor);
        $this->vendorId = $vendor->getId()->toRfc4122();
        $this->em()->flush();
    }

    public function testDraftsCodesAndVendorsMoveWhileIssuedDocumentsKeepTheProductMergedAway(): void
    {
        $this->signedIn();
        [$kept, $merged, $other] = [$this->products['VIS-6'], $this->products['VIS-6B'], $this->products['VIS-6C']];
        $draftInvoice = $this->anInvoice($merged);
        $issuedInvoice = $this->anInvoice($merged, issued: true);
        $draftQuote = $this->aQuote($merged);
        $draftNote = $this->aDeliveryNote($merged);
        $validatedNote = $this->aDeliveryNote($merged, validated: true);
        $draftOrder = $this->anOrder($merged);
        $sentOrder = $this->anOrder($merged, sent: true);
        $this->aCode($merged, 'SFX-88');
        $this->supplies($merged);
        $invoiceVersion = $this->versionOf('invoice', $draftInvoice);

        $merge = $this->merged($kept, [$merged, $other]);

        self::assertSame(['merge', ['id' => $kept, 'label' => 'VIS-6 VIS-6']], [$merge['kind'], $merge['kept']]);
        foreach ([$merged, $other] as $id) {
            self::assertSame(['merged_into_id' => $kept, 'is_active' => false], $this->em()->getConnection()->fetchAssociative('SELECT merged_into_id::text AS merged_into_id, is_active FROM product WHERE id = ?', [$id]));
        }
        self::assertSame([$kept, $merged], [$this->productOf('invoice_line', 'invoice_id', $draftInvoice), $this->productOf('invoice_line', 'invoice_id', $issuedInvoice)]);
        self::assertSame($kept, $this->productOf('quote_line', 'quote_id', $draftQuote));
        self::assertSame([$kept, $merged], [$this->productOf('delivery_note_line', 'delivery_note_id', $draftNote), $this->productOf('delivery_note_line', 'delivery_note_id', $validatedNote)]);
        self::assertSame([$kept, $merged], [$this->productOf('purchase_order_line', 'purchase_order_id', $draftOrder), $this->productOf('purchase_order_line', 'purchase_order_id', $sentOrder)]);
        self::assertSame($kept, $this->em()->getConnection()->fetchOne("SELECT product_id::text FROM product_barcode WHERE code = 'SFX-88'"));
        self::assertSame([$kept], $this->em()->getConnection()->fetchFirstColumn('SELECT product_id::text FROM product_vendor WHERE vendor_id = ?', [$this->vendorId]));
        // A draft whose lines moved is another version of it: an editor that had it open saves over it only knowingly.
        self::assertSame($invoiceVersion + 1, $this->versionOf('invoice', $draftInvoice));
        self::assertEqualsCanonicalizing([$merged, $other], $this->em()->getConnection()->fetchFirstColumn("SELECT entity_id::text FROM audit_log WHERE action = 'product.merged'"));
    }

    public function testUndoingTheMergeSwitchesTheProductsBackOnAndPointsEveryLineBack(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $draftInvoice = $this->anInvoice($merged);
        $draftOrder = $this->anOrder($merged);
        $this->aCode($merged, 'SFX-88');
        $orderVersion = $this->versionOf('purchase_order', $draftOrder);

        $merge = $this->merged($kept, [$merged]);
        $this->postJson($this->path('record-removals/'.$this->stringAt($merge, 'id').'/restore'), null);

        self::assertResponseIsSuccessful();
        self::assertSame(['merged_into_id' => null, 'is_active' => true], $this->em()->getConnection()->fetchAssociative('SELECT merged_into_id::text AS merged_into_id, is_active FROM product WHERE id = ?', [$merged]));
        self::assertSame([$merged, $merged], [$this->productOf('invoice_line', 'invoice_id', $draftInvoice), $this->productOf('purchase_order_line', 'purchase_order_id', $draftOrder)]);
        self::assertSame($merged, $this->em()->getConnection()->fetchOne("SELECT product_id::text FROM product_barcode WHERE code = 'SFX-88'"));
        // Its lines moved there and back: two versions, so an editor opened in between saves over neither unknowingly.
        self::assertSame($orderVersion + 2, $this->versionOf('purchase_order', $draftOrder));
    }

    /** Once the product kept is on an invoice issued since, the merge is part of what happened: it is no longer undone. */
    public function testTheMergeIsNotUndoneOnceTheProductKeptWasInvoiced(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $merge = $this->stringAt($this->merged($kept, [$merged]), 'id');
        $this->anInvoice($kept, issued: true);

        $this->postJson($this->path('record-removals/'.$merge.'/restore'), null);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['error' => 'merge_transacted', 'uses' => [['table' => 'invoice_line', 'count' => 1]]], $this->json());
        self::assertSame($kept, $this->em()->getConnection()->fetchOne('SELECT merged_into_id::text FROM product WHERE id = ?', [$merged]));
    }

    /** A draft written for the product kept since sells nothing yet: the merge is still undone. */
    public function testADraftForTheProductKeptSinceLeavesTheMergeUndone(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $merge = $this->stringAt($this->merged($kept, [$merged]), 'id');
        $this->anInvoice($kept);
        $this->aQuote($kept);

        $this->postJson($this->path('record-removals/'.$merge.'/restore'), null);

        self::assertResponseIsSuccessful();
    }

    public function testProductsOfAnotherUnitKindOrTrackingAreNotMerged(): void
    {
        $this->signedIn();
        $kept = $this->products['VIS-6'];
        foreach (['VIS-P' => 'unit_id', 'POSE' => 'kind', 'VIS-L' => 'tracking'] as $reference => $column) {
            $this->postJson($this->path('record-merges'), ['subject' => 'product', 'keptId' => $kept, 'ids' => [$this->products[$reference]]]);

            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
            self::assertSame(['error' => 'merge_mismatch', 'column' => $column], $this->json());
        }
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM product WHERE merged_into_id IS NOT NULL'));
    }

    /**
     * The goods a product merged away holds go to the product kept where they stand, worth what they were worth there:
     * the stock is one product's from then on, and its worth the two products' together. The undo takes them back.
     */
    public function testGoodsOnHandMoveToTheProductKeptAtWhatTheyWereWorth(): void
    {
        $this->signedIn();
        [$kept, $merged, $other] = [$this->products['VIS-6'], $this->products['VIS-6B'], $this->products['VIS-6C']];
        $this->received($kept, '3', '4.000');
        $this->received($merged, '5', '2.000');
        $this->received($other, '2', '2.000');
        $this->receivedEarlier();

        $merge = $this->merged($kept, [$merged, $other]);

        self::assertSame(['10.000', '0.000', '0.000'], [$this->onHand($kept), $this->onHand($merged), $this->onHand($other)]);
        // (3 × 4 + 5 × 2 + 2 × 2) / 10
        self::assertSame('2.6000', $this->worthOf($kept));
        self::assertSame(4, $this->em()->getConnection()->fetchOne("SELECT count(*)::int FROM stock_movement WHERE source_type = 'merge'"));

        $this->postJson($this->path('record-removals/'.$this->stringAt($merge, 'id').'/restore'), null);

        self::assertResponseIsSuccessful();
        self::assertSame(['3.000', '5.000', '2.000'], [$this->onHand($kept), $this->onHand($merged), $this->onHand($other)]);
        self::assertSame('4.0000', $this->worthOf($kept));
    }

    /** A shortage is the shelf's as much as goods are: what a product merged away sold beyond its stock is the product kept's to make up. */
    public function testAShortageMovesToTheProductKeptToo(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $this->received($kept, '3', '4.000');
        $this->em()->getConnection()->executeStatement("INSERT INTO stock_movement (id, company_id, product_id, location_id, kind, quantity, source_type, reason, at, cost_typed, cost_to_complete) VALUES (?, ?, ?, ?, 'out', -2, 'loss', 'broken', now() - interval '1 hour', false, false)", [Uuid::v7()->toRfc4122(), $this->companyId(), $merged, $this->site()]);

        $this->merged($kept, [$merged]);

        self::assertSame(['1.000', '0.000'], [$this->onHand($kept), $this->onHand($merged)]);
    }

    public function testALotGoesToTheProductKeptUnderItsCodeAndDate(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-L'], $this->products['VIS-L2']];
        $this->received($merged, '4', '1.000', 'L-1', '2027-01-31');
        $this->receivedEarlier();

        $merge = $this->merged($kept, [$merged]);

        self::assertSame(['4.000', '2027-01-31'], $this->lotOf($kept, 'L-1'));
        self::assertSame(['0.000', '2027-01-31'], $this->lotOf($merged, 'L-1'));
        $this->postJson($this->path('record-removals/'.$this->stringAt($merge, 'id').'/restore'), null);
        self::assertResponseIsSuccessful();
        self::assertSame(['0.000', '2027-01-31'], $this->lotOf($kept, 'L-1'));
        self::assertSame(['4.000', '2027-01-31'], $this->lotOf($merged, 'L-1'));
    }

    /** A serial number is one piece: the product kept holding it already, the two pieces are the shop's to sort out. */
    public function testASerialNumberTheProductKeptAlreadyHoldsRefusesTheMerge(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['PC-S'], $this->products['PC-S2']];
        $this->received($kept, '1', '900.000', 'SN-1');
        $this->received($merged, '1', '900.000', 'SN-1');

        $this->postJson($this->path('record-merges'), ['subject' => 'product', 'keptId' => $kept, 'ids' => [$merged]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['merge_refused', 'serial_held', 'SN-1'], [$this->json()['error'] ?? null, $this->json()['reason'] ?? null, $this->json()['code'] ?? null]);
        self::assertSame(['1.000', '1.000'], [$this->onHand($kept), $this->onHand($merged)]);
    }

    public function testSerialNumbersOfTheProductMergedAwayGoToTheProductKept(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['PC-S'], $this->products['PC-S2']];
        $this->received($kept, '1', '900.000', 'SN-1');
        $this->received($merged, '1', '900.000', 'SN-2');
        $this->receivedEarlier();

        $this->merged($kept, [$merged]);

        self::assertSame(['1.000', '1.000', '0.000'], [$this->lotOf($kept, 'SN-1')[0], $this->lotOf($kept, 'SN-2')[0], $this->lotOf($merged, 'SN-2')[0]]);
    }

    /** One lot has one date: two lots of one code dated differently are two things the shop must sort out first. */
    public function testALotOfTheSameCodeDatedOtherwiseRefusesTheMerge(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-L'], $this->products['VIS-L2']];
        $this->received($kept, '1', '1.000', 'L-1', '2027-01-31');
        $this->received($merged, '1', '1.000', 'L-1', '2027-03-31');

        $this->postJson($this->path('record-merges'), ['subject' => 'product', 'keptId' => $kept, 'ids' => [$merged]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['merge_refused', 'lot_dated_otherwise', 'L-1'], [$this->json()['error'] ?? null, $this->json()['reason'] ?? null, $this->json()['code'] ?? null]);
        self::assertSame(['1.000', '2027-03-31'], $this->lotOf($merged, 'L-1'));
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT merged_into_id FROM product WHERE id = ?', [$merged]));
    }

    /** Carrying goods moves what the product kept is worth: a cost, which only whoever reads costs moves. Nothing to carry, nothing asked. */
    /**
     * Goods whose receipt still waits for its cost are worth nothing known yet: carried, they would reach the product
     * kept at a provisional worth the cost entered later could no longer correct, so the cost comes first.
     */
    public function testGoodsWhoseReceiptAwaitsItsCostRefuseTheMerge(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $this->received($merged, '3', '2.000');
        $this->em()->getConnection()->executeStatement('UPDATE stock_movement SET unit_cost = NULL, cost_to_complete = true WHERE product_id = ?', [$merged]);

        $this->postJson($this->path('record-merges'), ['subject' => 'product', 'keptId' => $kept, 'ids' => [$merged]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['merge_refused', 'cost_pending', 'VIS-6B'], [$this->json()['error'] ?? null, $this->json()['reason'] ?? null, $this->json()['reference'] ?? null]);
        self::assertSame(['3.000', '0.000'], [$this->onHand($merged), $this->onHand($kept)]);
    }

    public function testGoodsOfTheProductKeptWhoseReceiptAwaitsItsCostRefuseTheMerge(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $this->received($kept, '3', '2.000');
        $this->received($merged, '2', '2.000');
        $this->em()->getConnection()->executeStatement('UPDATE stock_movement SET unit_cost = NULL, cost_to_complete = true WHERE product_id = ?', [$kept]);

        $this->postJson($this->path('record-merges'), ['subject' => 'product', 'keptId' => $kept, 'ids' => [$merged]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['merge_refused', 'cost_pending', 'VIS-6'], [$this->json()['error'] ?? null, $this->json()['reason'] ?? null, $this->json()['reference'] ?? null]);
        self::assertSame(['2.000', '3.000'], [$this->onHand($merged), $this->onHand($kept)]);
    }

    public function testCarryingGoodsTakesSeeingCosts(): void
    {
        $this->signedIn();
        [$kept, $merged, $other] = [$this->products['VIS-6'], $this->products['VIS-6B'], $this->products['VIS-6C']];
        $this->received($merged, '5', '2.000');
        $this->signedIn(email: 'stock@twes.local', costs: false);

        $this->postJson($this->path('record-merges'), ['subject' => 'product', 'keptId' => $kept, 'ids' => [$merged]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['merge_refused', 'costs_unseen'], [$this->json()['error'] ?? null, $this->json()['reason'] ?? null]);
        self::assertSame('5.000', $this->onHand($merged));

        $this->merged($kept, [$other]);
    }

    /** What arrives or comes back for a product merged away arrives for the product kept: the alias holds nothing again. */
    public function testGoodsArrivingForTheProductMergedAwayGoToTheProductKept(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $order = $this->anOrder($merged, sent: true);
        $this->merged($kept, [$merged]);

        $line = $this->em()->getConnection()->fetchOne('SELECT id::text FROM purchase_order_line WHERE purchase_order_id = ?', [$order]);
        $this->postJson($this->path("purchase-orders/$order/receipts"), ['receivedOn' => (new \DateTimeImmutable())->format('Y-m-d'), 'lines' => [['orderLineId' => $line, 'locationId' => $this->site(), 'quantity' => '4']]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['4.000', '0.000'], [$this->onHand($kept), $this->onHand($merged)]);
        // The order keeps the product it was sent for.
        self::assertSame($merged, $this->productOf('purchase_order_line', 'purchase_order_id', $order));
    }

    /** The goods a delivery handed over come back for the product kept when the note is cancelled after the merge. */
    public function testGoodsComingBackForTheProductMergedAwayGoToTheProductKept(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $this->received($merged, '5', '2.000');
        $note = $this->aDeliveryNote($merged);
        $this->postJson($this->path("delivery-notes/$note/validate"), null);
        self::assertResponseIsSuccessful();
        $this->merged($kept, [$merged]);
        self::assertSame(['4.000', '0.000'], [$this->onHand($kept), $this->onHand($merged)]);

        $this->postJson($this->path("delivery-notes/$note/cancel"), null);

        self::assertResponseIsSuccessful();
        self::assertSame(['5.000', '0.000'], [$this->onHand($kept), $this->onHand($merged)]);
    }

    /**
     * Whatever writes stock, the store refuses a movement of a product merged away, read under the stock's locks: a
     * writer that read the product before the merge cannot put goods back on it once the merge carried them away.
     */
    public function testTheStoreRefusesAMovementOfAProductMergedAway(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $this->merged($kept, [$merged]);
        // The place is read through a request first, which starts the kernel afresh and would let go of what was read before.
        $site = $this->site();
        $product = $this->em()->find(Product::class, Uuid::fromString($merged));
        $location = $this->em()->find(StockLocation::class, Uuid::fromString($site));
        self::assertNotNull($product);
        self::assertNotNull($location);

        try {
            static::getContainer()->get(StockMovementRepository::class)->save(StockMovement::receipt($product, $location, '1.000', null, new \DateTimeImmutable(), unitCost: '2.000'));
            self::fail('A movement of a product merged away is refused.');
        } catch (InvalidStockMovement $refused) {
            self::assertSame('product_merged', $refused->reason);
        }
    }

    /**
     * A product kept and later merged into a third takes the products merged into it along: they stand for the third
     * from then on, so goods coming back for any of them reach the one product still standing.
     */
    public function testAProductMergedIntoOneMergedSinceStandsForTheLastOneKept(): void
    {
        $this->signedIn();
        [$first, $second, $last] = [$this->products['VIS-6B'], $this->products['VIS-6'], $this->products['VIS-6C']];
        $this->received($first, '5', '2.000');
        $note = $this->aDeliveryNote($first);
        $this->postJson($this->path("delivery-notes/$note/validate"), null);
        self::assertResponseIsSuccessful();
        $this->merged($second, [$first]);
        $this->merged($last, [$second]);
        self::assertSame($last, $this->em()->getConnection()->fetchOne('SELECT merged_into_id::text FROM product WHERE id = ?', [$first]));
        self::assertSame(['4.000', '0.000', '0.000'], [$this->onHand($last), $this->onHand($second), $this->onHand($first)]);

        $this->postJson($this->path("delivery-notes/$note/cancel"), null);

        self::assertResponseIsSuccessful();
        self::assertSame(['5.000', '0.000', '0.000'], [$this->onHand($last), $this->onHand($second), $this->onHand($first)]);
    }

    /** Merges are undone last first: the earlier one waits while the product it kept is merged into another. */
    public function testAMergeWhoseProductKeptWasMergedSinceIsUndoneOnlyAfterThatOne(): void
    {
        $this->signedIn();
        [$first, $second, $last] = [$this->products['VIS-6B'], $this->products['VIS-6'], $this->products['VIS-6C']];
        $this->received($first, '5', '2.000');
        $this->receivedEarlier();
        $earlier = $this->stringAt($this->merged($second, [$first]), 'id');
        $later = $this->stringAt($this->merged($last, [$second]), 'id');

        $this->postJson($this->path("record-removals/$earlier/restore"), null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('merge_alias', $this->json()['error'] ?? null);
        $this->postJson($this->path("record-removals/$later/restore"), null);
        self::assertResponseIsSuccessful();

        $pointing = fn (string $id): mixed => $this->em()->getConnection()->fetchOne('SELECT merged_into_id::text FROM product WHERE id = ?', [$id]);
        self::assertSame([$second, null], [$pointing($first), $pointing($second)]);
        self::assertSame(['5.000', '0.000', '0.000'], [$this->onHand($second), $this->onHand($last), $this->onHand($first)]);
        $this->postJson($this->path("record-removals/$earlier/restore"), null);
        self::assertResponseIsSuccessful();
        self::assertSame(['0.000', '5.000'], [$this->onHand($second), $this->onHand($first)]);
    }

    /** Goods of a lot come back to the lot of that code the merge gave the product kept, never to the other's. */
    public function testGoodsOfALotComingBackForTheProductMergedAwayGoToTheLotOfTheProductKept(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-L'], $this->products['VIS-L2']];
        $this->received($merged, '5', '2.000', 'L-1', '2027-01-31');
        $this->postJson($this->path('delivery-notes'), ['customerId' => $this->customerId, 'establishmentId' => null, 'deliveryDate' => null, 'deliveryAddressLine1' => null, 'deliveryAddressLine2' => null, 'deliveryPostalCode' => null, 'deliveryCity' => null, 'deliveryCountryCode' => null, 'customerReference' => null, 'remarksPrinted' => null, 'notesInternal' => null, 'lines' => [['productId' => $merged, 'quantity' => '2', 'lotCode' => 'L-1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $note = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path("delivery-notes/$note/validate"), null);
        self::assertResponseIsSuccessful();
        self::assertSame(['3.000', '2027-01-31'], $this->lotOf($merged, 'L-1'));
        $this->merged($kept, [$merged]);

        $this->postJson($this->path("delivery-notes/$note/cancel"), null);

        self::assertResponseIsSuccessful();
        self::assertSame(['5.000', '2027-01-31'], $this->lotOf($kept, 'L-1'));
        self::assertSame(['0.000', '2027-01-31'], $this->lotOf($merged, 'L-1'));
    }

    /** A draft made from a document sent before the merge is a new draft: it names the product kept, as every draft does. */
    public function testADraftMadeFromADocumentOfTheProductMergedAwayNamesTheProductKept(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $this->received($merged, '5', '2.000');
        $note = $this->aDeliveryNote($merged);
        $this->postJson($this->path("delivery-notes/$note/validate"), null);
        self::assertResponseIsSuccessful();
        $quote = $this->aQuote($merged);
        $this->postJson($this->path("quotes/$quote/send"), null);
        self::assertResponseIsSuccessful();
        $this->merged($kept, [$merged]);

        $this->postJson($this->path('invoices/from-delivery-notes'), ['deliveryNoteIds' => [$note]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($kept, $this->productOf('invoice_line', 'invoice_id', $this->stringAt($this->json(), 'id')));

        $this->postJson($this->path("quotes/$quote/accept"), ['answeredOn' => null]);
        $this->postJson($this->path("quotes/$quote/invoice"), null);
        self::assertResponseIsSuccessful();
        self::assertSame($kept, $this->productOf('invoice_line', 'invoice_id', $this->stringAt($this->json(), 'invoiceId')));
        // The documents themselves keep the product they were made for.
        self::assertSame([$merged, $merged], [$this->productOf('delivery_note_line', 'delivery_note_id', $note), $this->productOf('quote_line', 'quote_id', $quote)]);
    }

    /** A copy of an invoice issued before the merge is a new sale: it names the product kept, the original the one it sold. */
    public function testACopyOfAnInvoiceOfTheProductMergedAwayNamesTheProductKept(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $invoice = $this->anInvoice($merged, issued: true);
        $this->merged($kept, [$merged]);

        $this->postJson($this->path("invoices/$invoice/duplicate"), null);

        self::assertResponseIsSuccessful();
        self::assertSame([$kept, $merged], [$this->productOf('invoice_line', 'invoice_id', $this->stringAt($this->json(), 'id')), $this->productOf('invoice_line', 'invoice_id', $invoice)]);
    }

    /** Goods the product kept received since the merge are part of what happened: the merge is no longer undone. */
    public function testTheMergeIsNotUndoneOnceTheProductKeptReceivedGoods(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $merge = $this->merged($kept, [$merged]);
        $this->received($kept, '1', '1.000');

        $this->postJson($this->path('record-removals/'.$this->stringAt($merge, 'id').'/restore'), null);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('merge_transacted', $this->json()['error'] ?? null);
    }

    public function testThePreviewSaysWhatMovesAndWhatStays(): void
    {
        $this->signedIn();
        $merged = $this->products['VIS-6B'];
        $this->anInvoice($merged);
        $this->anInvoice($merged, issued: true);
        $this->anOrder($merged);
        $this->aCode($merged, 'SFX-88');

        $this->getJson($this->path('record-merges/preview?subject=product&ids[]='.$merged));

        self::assertResponseIsSuccessful();
        self::assertSame([[
            'id' => $merged,
            'label' => 'VIS-6B VIS-6B',
            'moves' => [['table' => 'invoice_line', 'count' => 1], ['table' => 'product_barcode', 'count' => 1], ['table' => 'purchase_order_line', 'count' => 1]],
            'stays' => [['table' => 'invoice_line', 'count' => 1]],
        ]], $this->json());
    }

    /** What still names a product merged away is never written anew: its drafts are refused it, its form is read again. */
    public function testNothingNewNamesAProductMergedAway(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $this->merged($kept, [$merged]);

        $lines = [['productId' => $merged, 'quantity' => '2']];
        foreach ([
            'invoices' => $this->invoiceBody($merged),
            'quotes' => ['customerId' => $this->customerId, 'establishmentId' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'lines' => $lines],
            'delivery-notes' => ['customerId' => $this->customerId, 'establishmentId' => null, 'deliveryDate' => null, 'customerReference' => null, 'notesInternal' => null, 'lines' => $lines],
            'purchase-orders' => ['vendorId' => $this->vendorId, 'lines' => [['productId' => $merged, 'quantity' => '10']]],
        ] as $documents => $body) {
            $this->postJson($this->path($documents), $body);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $documents);
            self::assertStringContainsString('The product VIS-6B was merged into VIS-6', $this->stringAt($this->json(), 'detail'), $documents);
        }
        $this->sendJson('PUT', $this->path("products/$merged"), $this->productBody('VIS-6B', $this->storedVersion('product', $merged)));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testListsAndPickersShowTheProductKept(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $this->merged($kept, [$merged]);

        $this->getJson($this->path('products?search=VIS-6'));
        self::assertResponseIsSuccessful();
        self::assertNotContains($merged, array_map(fn (array $row): string => $this->stringAt($row, 'id'), $this->jsonList()));
        $this->getJson($this->path("products/$merged"));
        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $kept, 'reference' => 'VIS-6', 'name' => 'VIS-6'], $this->json()['mergedInto'] ?? null);
    }

    public function testWhatTheProductMergedAwaySoldIsReportedUnderTheProductKept(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $invoice = $this->anInvoice($merged, issued: true);
        // The report reads what issuing writes; issuing for real is the invoices' own tests.
        $connection = $this->em()->getConnection();
        $connection->executeStatement('UPDATE invoice SET issue_date = CURRENT_DATE, total_net = 12, amount_due = 12 WHERE id = ?', [$invoice]);
        $connection->executeStatement('UPDATE invoice_line SET line_net = 12 WHERE invoice_id = ?', [$invoice]);
        $this->merged($kept, [$merged]);

        $today = $connection->fetchOne('SELECT CURRENT_DATE::text');
        self::assertIsString($today);
        $this->getJson($this->path("reports/sales-by-product?from=$today&to=$today"));

        self::assertResponseIsSuccessful();
        $rows = array_map(static fn (mixed $row): mixed => \is_array($row) ? ($row['cells'] ?? null) : null, $this->arrayAt($this->json(), 'rows'));
        self::assertSame(['VIS-6'], array_map(static fn (mixed $cells): mixed => \is_array($cells) ? ($cells['product'] ?? null) : null, $rows));
        // The books keep the product the line named.
        self::assertSame($merged, $this->productOf('invoice_line', 'invoice_id', $invoice));
    }

    /**
     * The stock reports read the product kept as one product with the goods merged into it: the goods carried move in
     * no column, as a move between places does, and what the product merged away sold counts as the product kept's
     * sale. The movements list narrowed to the product kept shows what was moved under the product merged away too,
     * each movement naming the product it moved.
     */
    public function testTheStockReportsAndMovementsReadTheProductKeptWithWhatWasMergedIntoIt(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $this->received($merged, '5', '2.000');
        $note = $this->aDeliveryNote($merged);
        $this->postJson($this->path("delivery-notes/$note/validate"), null);
        self::assertResponseIsSuccessful();
        // Received and sold a hundred days ago: quiet for longer than the company's ninety days.
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE stock_movement SET at = at - interval '100 days'");
        $this->merged($kept, [$merged]);
        $from = $connection->fetchOne('SELECT (CURRENT_DATE - 1)::text');
        $to = $connection->fetchOne('SELECT (CURRENT_DATE + 1)::text');
        self::assertIsString($from);
        self::assertIsString($to);

        $this->getJson($this->path("reports/stock-movements?from=$from&to=$to"));
        self::assertResponseIsSuccessful();
        self::assertSame([['VIS-6', '4.000', '0.000', '0.000', '4.000']], $this->reportRows(['reference', 'opening', 'received', 'sold', 'closing']));

        $this->getJson($this->path('reports/dead-stock'));
        self::assertResponseIsSuccessful();
        self::assertSame([['VIS-6', '4.000', $connection->fetchOne("SELECT ((now() - interval '100 days') AT TIME ZONE 'Africa/Tunis')::date::text")]], $this->reportRows(['reference', 'on_hand', 'last_sold']));

        $this->getJson($this->path("stock-movements?productId[]=$kept"));
        self::assertResponseIsSuccessful();
        self::assertSame(4, $this->jsonPage()['totalItems'], 'the receipt, the sale and the merge’s two halves');
    }

    public function testWhatIsRunningOutReadsTheProductKeptAtThePaceOfWhatWasMergedIntoIt(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $this->received($merged, '2', '2.000');
        $note = $this->aDeliveryNote($merged);
        $this->postJson($this->path("delivery-notes/$note/validate"), null);
        self::assertResponseIsSuccessful();
        $this->em()->persist(new Setting(SettingAddress::company($this->em()->find(Company::class, $this->company->getId()) ?? $this->company), 'watch.lead_days', 40, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->merged($kept, [$merged]);

        $this->getJson($this->path('watch/stock.running_out'));
        self::assertResponseIsSuccessful();
        // One sold in thirty days and one left: thirty days, the carry's leaving half being no sale.
        self::assertSame([[$kept, '1.000', 30]], array_map(static fn (array $row): array => [$row['subjectId'] ?? null, \is_array($row['params'] ?? null) ? $row['params']['onHand'] ?? null : null, \is_array($row['params'] ?? null) ? $row['params']['days'] ?? null : null], $this->jsonList()));
    }

    /** « Pas vendu » reads the product kept with what was sold under the product merged into it, as the dead stock does. */
    public function testWhatTheProductMergedAwaySoldKeepsTheProductKeptOffTheUnsold(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $this->soldDaysAgo($merged, 10);
        $this->merged($kept, [$merged]);

        $this->getJson($this->path('watch/invoices.unsold_products'));
        self::assertResponseIsSuccessful();
        self::assertNotContains($kept, array_column($this->jsonList(), 'subjectId'), 'sold ten days ago under the product merged into it');
    }

    public function testTheProductKeptHasGoneUnsoldSinceTheLastSaleOfWhatWasMergedIntoIt(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $this->soldDaysAgo($merged, 100);
        $this->merged($kept, [$merged]);

        $this->getJson($this->path('watch/invoices.unsold_products'));
        self::assertResponseIsSuccessful();
        $days = [];
        foreach ($this->jsonList() as $row) {
            $days[$this->stringAt($row, 'subjectId')] = \is_array($row['params'] ?? null) ? $row['params']['days'] ?? null : null;
        }
        self::assertSame(100, $days[$kept] ?? null, 'unsold since the sale under the product merged into it, not since it was created 120 days ago');
    }

    /** A sale of the product merged away whose goods came back to the product kept, in its lot, was never a sale. */
    public function testASaleComingBackToTheProductKeptIsNoSaleForTheDeadStock(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-L'], $this->products['VIS-L2']];
        $this->received($merged, '5', '2.000', 'L-1', '2027-01-31');
        $this->postJson($this->path('delivery-notes'), ['customerId' => $this->customerId, 'establishmentId' => null, 'deliveryDate' => null, 'deliveryAddressLine1' => null, 'deliveryAddressLine2' => null, 'deliveryPostalCode' => null, 'deliveryCity' => null, 'deliveryCountryCode' => null, 'customerReference' => null, 'remarksPrinted' => null, 'notesInternal' => null, 'lines' => [['productId' => $merged, 'quantity' => '2', 'lotCode' => 'L-1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $note = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path("delivery-notes/$note/validate"), null);
        self::assertResponseIsSuccessful();
        $this->em()->getConnection()->executeStatement("UPDATE stock_movement SET at = at - interval '100 days'");
        $this->merged($kept, [$merged]);
        $this->postJson($this->path("delivery-notes/$note/cancel"), null);
        self::assertResponseIsSuccessful();

        $this->getJson($this->path('reports/dead-stock'));
        self::assertResponseIsSuccessful();
        self::assertSame([['VIS-L', '5.000', null, 100]], $this->reportRows(['reference', 'on_hand', 'last_sold', 'quiet_days']));
    }

    public function testMergingProductsTakesWritingThemAndAProofGivenAgain(): void
    {
        $this->signedIn(prove: false);
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];

        $this->postJson($this->path('record-merges'), ['subject' => 'product', 'keptId' => $kept, 'ids' => [$merged]]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null);

        $this->createUser('reader@twes.local', self::PASSWORD, $this->em()->find(Company::class, $this->company->getId()) ?? $this->company, ['company.read', 'product.read'], 'reader');
        $this->login('reader@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('record-merges'), ['subject' => 'product', 'keptId' => $kept, 'ids' => [$merged]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM product WHERE merged_into_id IS NOT NULL'));
    }

    private function aProduct(string $reference, string $unit, ProductKind $kind, ProductTracking $tracking, \DateTimeImmutable $now): void
    {
        $found = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany($unit, $this->company->getId());
        self::assertInstanceOf(Unit::class, $found);
        $product = Product::create($this->company, $reference, new ProductDetails($reference, null, $kind, '0.500'), $found, null, [], $now);
        $product->track($tracking, $now);
        $this->em()->persist($product);
        $this->products[$reference] = $product->getId()->toRfc4122();
    }

    /**
     * @param list<string> $merged
     *
     * @return array<string, mixed>
     */
    private function merged(string $kept, array $merged): array
    {
        $this->postJson($this->path('record-merges'), ['subject' => 'product', 'keptId' => $kept, 'ids' => $merged]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->json();
    }

    /** Every product created 120 days ago, and an invoice of `$product` issued `$days` days ago. */
    private function soldDaysAgo(string $product, int $days): void
    {
        $invoice = $this->anInvoice($product, issued: true);
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE product SET created_at = now() - interval '120 days' WHERE company_id = ?", [$this->companyId()]);
        $connection->executeStatement('UPDATE invoice SET issue_date = CAST(? AS date) WHERE id = ?', [(new \DateTimeImmutable("-$days days"))->format('Y-m-d'), $invoice]);
    }

    /** @return array<string, mixed> */
    private function invoiceBody(string $product): array
    {
        return ['customerId' => $this->customerId, 'establishmentId' => null, 'supplyDate' => null, 'paymentTermsDays' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'documentTaxComponentIds' => null, 'lines' => [['productId' => $product, 'quantity' => '2']]];
    }

    private function anInvoice(string $product, bool $issued = false): string
    {
        $this->postJson($this->path('invoices'), $this->invoiceBody($product));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        if ($issued) {
            // The engine reads the document's state, not how it got there: issuing for real is the invoices' own tests.
            $this->em()->getConnection()->executeStatement("UPDATE invoice SET status = 'issued', issued_at = now() WHERE id = ?", [$id]);
        }

        return $id;
    }

    private function aQuote(string $product): string
    {
        $this->postJson($this->path('quotes'), ['customerId' => $this->customerId, 'establishmentId' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'lines' => [['productId' => $product, 'quantity' => '3']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function aDeliveryNote(string $product, bool $validated = false): string
    {
        $this->postJson($this->path('delivery-notes'), ['customerId' => $this->customerId, 'establishmentId' => null, 'deliveryDate' => null, 'deliveryAddressLine1' => null, 'deliveryAddressLine2' => null, 'deliveryPostalCode' => null, 'deliveryCity' => null, 'deliveryCountryCode' => null, 'customerReference' => null, 'remarksPrinted' => null, 'notesInternal' => null, 'lines' => [['productId' => $product, 'quantity' => '1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        if ($validated) {
            $this->em()->getConnection()->executeStatement("UPDATE delivery_note SET status = 'validated' WHERE id = ?", [$id]);
        }

        return $id;
    }

    private function anOrder(string $product, bool $sent = false): string
    {
        $this->postJson($this->path('purchase-orders'), ['vendorId' => $this->vendorId, 'lines' => [['productId' => $product, 'quantity' => '10']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        if ($sent) {
            $this->postJson($this->path("purchase-orders/$id/send"), null);
            self::assertResponseIsSuccessful();
        }

        return $id;
    }

    /** Goods received at the company's first place, at a cost, in a lot when the product is tracked by lot. */
    private function received(string $product, string $quantity, string $cost, ?string $lot = null, ?string $expiresOn = null): void
    {
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $product, 'locationId' => $this->site(), 'quantity' => $quantity, 'unitCost' => $cost, 'lotCode' => $lot, 'lotExpiresOn' => $expiresOn]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    /**
     * The goods arrived a minute before the merge: a movement of the product kept dated in the merge's own second is
     * taken as one after it, which would leave the merge no longer undone.
     */
    private function receivedEarlier(): void
    {
        $this->em()->getConnection()->executeStatement("UPDATE stock_movement SET at = at - interval '1 minute'");
    }

    /**
     * @param list<string> $columns
     *
     * @return list<list<mixed>> the report's rows, each the cells of these columns
     */
    private function reportRows(array $columns): array
    {
        return array_values(array_map(static function (mixed $row) use ($columns): array {
            $cells = \is_array($row) && \is_array($row['cells'] ?? null) ? $row['cells'] : [];

            return array_map(static fn (string $column): mixed => $cells[$column] ?? null, $columns);
        }, $this->arrayAt($this->json(), 'rows')));
    }

    private function site(): string
    {
        // The company's first place is made the first time its places are read.
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();

        return $this->stringAt($this->jsonList()[0], 'id');
    }

    private function onHand(string $product): string
    {
        $quantity = $this->em()->getConnection()->fetchOne('SELECT COALESCE(sum(quantity), 0)::numeric(14, 3)::text FROM stock_movement WHERE product_id = ?', [$product]);
        self::assertIsString($quantity);

        return $quantity;
    }

    /** What one unit of the product's stock is worth: its movements' value over its quantity. */
    private function worthOf(string $product): string
    {
        $worth = $this->em()->getConnection()->fetchOne('SELECT round(sum(quantity * unit_cost + COALESCE(revaluation, 0)) / sum(quantity), 4)::text FROM stock_movement WHERE product_id = ?', [$product]);
        self::assertIsString($worth);

        return $worth;
    }

    /** @return array{string, ?string} what the product's lot of this code holds, and its date */
    private function lotOf(string $product, string $code): array
    {
        $lot = $this->em()->getConnection()->fetchAssociative('SELECT (SELECT COALESCE(sum(m.quantity), 0)::numeric(14, 3)::text FROM stock_movement m WHERE m.lot_id = l.id) AS held, l.expires_on::text AS expires_on FROM stock_lot l WHERE l.product_id = ? AND l.code = ?', [$product, $code]);
        self::assertIsArray($lot, "$code is a lot of the product");
        self::assertIsString($lot['held']);
        self::assertTrue(null === $lot['expires_on'] || \is_string($lot['expires_on']));

        return [$lot['held'], $lot['expires_on']];
    }

    private function aCode(string $product, string $code): void
    {
        $this->em()->getConnection()->insert('product_barcode', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $this->companyId(), 'product_id' => $product, 'code' => $code, 'match_key' => $code, 'role' => 'unit', 'quantity' => 1, 'created_at' => '2026-10-20 08:00:00']);
    }

    private function supplies(string $product): void
    {
        $this->em()->getConnection()->insert('product_vendor', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $this->companyId(), 'product_id' => $product, 'vendor_id' => $this->vendorId, 'created_at' => '2026-10-20 08:00:00']);
    }

    private function productOf(string $table, string $parent, string $id): ?string
    {
        $product = $this->em()->getConnection()->fetchOne(\sprintf('SELECT product_id::text FROM %s WHERE %s = ?', $table, $parent), [$id]);

        return \is_string($product) ? $product : null;
    }

    private function versionOf(string $table, string $id): int
    {
        $version = $this->em()->getConnection()->fetchOne(\sprintf('SELECT version FROM %s WHERE id = ?', $table), [$id]);
        self::assertIsInt($version);

        return $version;
    }

    /** @return array<string, mixed> */
    private function productBody(string $reference, int $version): array
    {
        return ['reference' => $reference, 'name' => $reference, 'description' => null, 'kind' => 'goods', 'unitPriceNet' => '0.500', 'unitId' => $this->unitIdOf('C62'), 'categoryId' => null, 'defaultTaxComponentIds' => [], 'isActive' => true, 'version' => $version];
    }

    private function unitIdOf(string $code): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT id::text FROM unit WHERE code = ? AND company_id = ?', [$code, $this->companyId()]);
        self::assertIsString($id);

        return $id;
    }

    private function signedIn(bool $prove = true, string $email = 'shop@twes.local', bool $costs = true): void
    {
        $permissions = ['company.read', 'customer.read', 'invoice.read', 'invoice.write', 'quote.read', 'quote.write', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate', 'vendor.read', 'purchase.read', 'purchase.write', 'purchase.receive', 'stock.read', 'stock.write', 'product.read', 'product.write', ...($costs ? ['product.cost.read'] : [])];
        $this->createUser($email, self::PASSWORD, $this->em()->find(Company::class, $this->company->getId()) ?? $this->company, $permissions, $costs ? 'member' : 'stock keeper');
        $this->login($email, self::PASSWORD);
        self::assertResponseIsSuccessful();
        if ($prove) {
            $this->stepUp(self::PASSWORD);
        }
    }

    private function companyId(): string
    {
        return $this->company->getId()->toRfc4122();
    }

    private function path(string $rest): string
    {
        return '/api/companies/'.$this->companyId().'/'.$rest;
    }
}

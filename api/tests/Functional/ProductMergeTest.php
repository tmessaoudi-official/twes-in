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

    /** Until a merge carries the stock across, a product with goods on hand is not merged away. */
    public function testAProductHoldingStockIsNotMergedAway(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->products['VIS-6'], $this->products['VIS-6B']];
        $this->aReceipt($merged);

        $this->postJson($this->path('record-merges'), ['subject' => 'product', 'keptId' => $kept, 'ids' => [$merged]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['error' => 'merge_refused', 'reason' => 'stock_on_hand'], $this->json());
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM product WHERE merged_into_id IS NOT NULL'));
        self::assertSame(0, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM record_removal WHERE subject = 'product'"));
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

        $this->postJson($this->path('invoices'), $this->invoiceBody($merged));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->postJson($this->path('purchase-orders'), ['vendorId' => $this->vendorId, 'lines' => [['productId' => $merged, 'quantity' => '10']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
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
            $this->em()->getConnection()->executeStatement("UPDATE purchase_order SET status = 'sent' WHERE id = ?", [$id]);
        }

        return $id;
    }

    private function aReceipt(string $product): void
    {
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();
        $site = $this->stringAt($this->jsonList()[0], 'id');
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $product, 'locationId' => $site, 'quantity' => '3']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
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

    private function signedIn(bool $prove = true): void
    {
        $permissions = ['company.read', 'customer.read', 'invoice.read', 'invoice.write', 'quote.read', 'quote.write', 'delivery_note.read', 'delivery_note.write', 'vendor.read', 'purchase.read', 'purchase.write', 'stock.read', 'stock.write', 'product.read', 'product.write', 'product.cost.read'];
        $this->createUser('shop@twes.local', self::PASSWORD, $this->company, $permissions, 'member');
        $this->login('shop@twes.local', self::PASSWORD);
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

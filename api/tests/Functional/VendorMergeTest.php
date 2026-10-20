<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Vendors merged into one stay, switched off, pointing at the vendor kept: their drafts, the products bought from them
 * and their own codes move to it, while what is in the books or the stock (a recorded expense, a sent order, a
 * movement) keeps naming them. « Annuler la fusion » puts everything back, until the vendor kept has received goods
 * or had an expense recorded since.
 */
final class VendorMergeTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    private Company $company;

    /** @var array<string, string> product id by reference */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        foreach (['VIS-6', 'BOU-8'] as $reference) {
            $product = Product::create($this->company, $reference, new ProductDetails($reference, null, ProductKind::Goods, '0.500'), $piece, null, [], $now);
            $this->em()->persist($product);
            $this->products[$reference] = $product->getId()->toRfc4122();
        }
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $vendors = [
            'F-001' => new VendorProfile('Sotumag', identifiers: ['matricule_fiscal' => '1234567A/B/M/000']),
            'F-002' => new VendorProfile('Sotumag Sfax', identifiers: ['matricule_fiscal' => '1234567A/B/M/000']),
            'F-003' => new VendorProfile('Sotumag Gabès'),
            'F-004' => new VendorProfile('Visserie du Sud', identifiers: ['matricule_fiscal' => '7654321B/A/M/000']),
        ];
        foreach ($vendors as $number => $profile) {
            $this->em()->persist(Vendor::create($this->company, $number, $profile, $now));
        }
        $this->em()->flush();
    }

    public function testDraftsProductsAndCodesMoveWhileTheBooksAndTheStockKeepTheVendorMergedAway(): void
    {
        $this->signedIn();
        [$kept, $merged, $other] = [$this->vendorId('F-001'), $this->vendorId('F-002'), $this->vendorId('F-003')];
        $this->supplies('VIS-6', $kept);
        $this->supplies('VIS-6', $merged);
        $this->supplies('BOU-8', $merged);
        $this->aSupplierCode('BOU-8', $merged, 'SFX-88');
        $draft = $this->anExpense($merged);
        $recorded = $this->anExpense($merged, record: true);
        $draftOrder = $this->anOrder($merged);
        $sentOrder = $this->anOrder($merged, sent: true);
        $movement = $this->aReceipt($merged);

        $merge = $this->merged($kept, [$merged, $other]);

        self::assertSame(['merge', ['id' => $kept, 'label' => 'F-001 Sotumag']], [$merge['kind'], $merge['kept']]);
        foreach ([$merged, $other] as $id) {
            self::assertSame(['merged_into_id' => $kept, 'is_active' => false], $this->em()->getConnection()->fetchAssociative('SELECT merged_into_id::text AS merged_into_id, is_active FROM vendor WHERE id = ?', [$id]));
        }
        self::assertSame([$kept, $merged], [$this->vendorOf('expense', $draft), $this->vendorOf('expense', $recorded)]);
        self::assertSame([$kept, $merged], [$this->vendorOf('purchase_order', $draftOrder), $this->vendorOf('purchase_order', $sentOrder)]);
        self::assertSame($merged, $this->vendorOf('stock_movement', $movement));
        // Bought from the vendor kept once, though both supplied it.
        self::assertEqualsCanonicalizing([[$this->products['BOU-8'], $kept], [$this->products['VIS-6'], $kept]], $this->supplied());
        self::assertSame($kept, $this->em()->getConnection()->fetchOne("SELECT supplier_id::text FROM product_barcode WHERE code = 'SFX-88'"));
        self::assertEqualsCanonicalizing([$merged, $other], $this->em()->getConnection()->fetchFirstColumn("SELECT entity_id::text FROM audit_log WHERE action = 'vendor.merged'"));
    }

    public function testUndoingTheMergeSwitchesTheVendorsBackOnAndPointsEveryLinkBack(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->vendorId('F-001'), $this->vendorId('F-002')];
        $this->supplies('VIS-6', $kept);
        $this->supplies('VIS-6', $merged);
        $this->aSupplierCode('BOU-8', $merged, 'SFX-88');
        $draft = $this->anExpense($merged);
        $before = $this->state();

        $merge = $this->merged($kept, [$merged]);
        $this->postJson($this->path('record-removals/'.$this->stringAt($merge, 'id').'/restore'), null);

        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->state());
        self::assertSame($merged, $this->vendorOf('expense', $draft));
        self::assertSame(1, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'vendor.merge_undone' AND entity_id = ?", [$merged]));
    }

    /** Once the vendor kept received goods, the merge is part of what happened: it is no longer undone. */
    public function testTheMergeIsNotUndoneOnceTheVendorKeptReceivedGoods(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->vendorId('F-001'), $this->vendorId('F-002')];
        $merge = $this->stringAt($this->merged($kept, [$merged]), 'id');
        $this->aReceipt($kept);

        $this->postJson($this->path('record-removals/'.$merge.'/restore'), null);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['error' => 'merge_transacted', 'uses' => [['table' => 'stock_movement', 'count' => 1]]], $this->json());
        self::assertSame($kept, $this->em()->getConnection()->fetchOne('SELECT merged_into_id::text FROM vendor WHERE id = ?', [$merged]));
    }

    /**
     * A draft the merge moved and that was recorded since is in the books under the vendor kept: an undo would rewrite
     * it, so it is refused. A draft written for the vendor kept since changes nothing.
     */
    public function testTheMergeIsNotUndoneOnceADraftItMovedWasRecorded(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->vendorId('F-001'), $this->vendorId('F-002')];
        $draft = $this->anExpense($merged);
        $merge = $this->stringAt($this->merged($kept, [$merged]), 'id');
        $this->anExpense($kept);
        $this->postJson($this->path('expenses/'.$draft.'/record'), null);
        self::assertResponseIsSuccessful();

        $this->postJson($this->path('record-removals/'.$merge.'/restore'), null);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['error' => 'merge_transacted', 'uses' => [['table' => 'expense', 'count' => 1]]], $this->json());
        self::assertSame($kept, $this->vendorOf('expense', $draft));
    }

    public function testAVendorStillInDraftsAloneSinceIsUndone(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->vendorId('F-001'), $this->vendorId('F-002')];
        $merge = $this->stringAt($this->merged($kept, [$merged]), 'id');
        $this->anExpense($kept);
        $this->anOrder($kept);

        $this->postJson($this->path('record-removals/'.$merge.'/restore'), null);

        self::assertResponseIsSuccessful();
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT merged_into_id FROM vendor WHERE id = ?', [$merged]));
    }

    /** A vendor merged away is no longer a choice: nothing is merged into it, it is merged no further, nor deleted. */
    public function testAVendorMergedAwayIsNoLongerAChoice(): void
    {
        $this->signedIn();
        [$kept, $merged, $other] = [$this->vendorId('F-001'), $this->vendorId('F-002'), $this->vendorId('F-003')];
        $this->merged($kept, [$merged]);

        foreach ([[$merged, [$other]], [$other, [$merged]], [$merged, [$kept]]] as [$into, $ids]) {
            $this->postJson($this->path('record-merges'), ['subject' => 'vendor', 'keptId' => $into, 'ids' => $ids]);
            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
            self::assertSame('merge_alias', $this->json()['error'] ?? null);
        }
        $this->postJson($this->path('record-removals'), ['subject' => 'vendor', 'ids' => [$merged]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('merge_alias', $this->json()['error'] ?? null);
        self::assertSame([$kept, null], $this->em()->getConnection()->fetchFirstColumn('SELECT merged_into_id::text FROM vendor WHERE id IN (?, ?) ORDER BY number', [$merged, $other]));
    }

    /**
     * Nothing new names a vendor merged away, from a screen read before the merge: an expense, a receipt, a supplier's
     * code; and the vendor itself is no longer revised, as what it was stays as it was merged.
     */
    public function testNothingNewNamesAVendorMergedAway(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->vendorId('F-001'), $this->vendorId('F-002')];
        $this->merged($kept, [$merged]);
        $tax = $this->em()->getConnection()->fetchOne('SELECT id FROM tax_component WHERE company_id = ? AND code = ?', [$this->companyId(), 'TVA19']);
        self::assertIsString($tax);

        $this->postJson($this->path('expenses'), [
            'date' => '2026-10-20', 'reference' => null, 'description' => 'Visserie', 'vendorId' => $merged,
            'categoryId' => $this->aCategory(), 'lines' => [['amountNet' => '10.000', 'taxComponentIds' => [$tax]]], 'notes' => null,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'vendorId', 'reason' => 'unknown_choice', 'params' => []]], $this->refusals());

        $this->getJson($this->path('stock-locations'));
        $site = $this->stringAt($this->jsonList()[0], 'id');
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->products['VIS-6'], 'locationId' => $site, 'quantity' => '3', 'vendorId' => $merged]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $product = $this->products['BOU-8'];
        $this->sendJson('PUT', $this->path("products/$product/barcodes"), ['version' => $this->storedVersion('product', $product), 'barcodes' => [['role' => 'supplier', 'code' => 'SFX-88', 'quantity' => 1, 'supplierId' => $merged]]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('merged into another', (string) $this->client->getResponse()->getContent());

        $this->sendJson('PUT', $this->path("vendors/$merged"), ['number' => 'F-002', 'name' => 'Sotumag Sfax II', 'isActive' => true, 'version' => $this->storedVersion('vendor', $merged)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $connection = $this->em()->getConnection();
        self::assertSame([0, 0, 0], array_map(static fn (string $table): mixed => $connection->fetchOne(\sprintf('SELECT count(*) FROM %s WHERE %s = ?', ...explode(':', $table)), [$merged]), ['expense:vendor_id', 'stock_movement:vendor_id', 'product_barcode:supplier_id']));
        self::assertSame(['Sotumag Sfax', false], [$connection->fetchOne('SELECT name FROM vendor WHERE id = ?', [$merged]), $connection->fetchOne('SELECT is_active FROM vendor WHERE id = ?', [$merged])]);
    }

    /**
     * A file written before the merge still names the vendor merged away: a product's supplier is taken as the vendor
     * kept, and said so on the row, while a row of the vendors' own file is refused, as it would rewrite what was merged.
     */
    public function testAFileNamingAVendorMergedAwayTakesTheVendorKept(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->vendorId('F-001'), $this->vendorId('F-002')];
        $this->merged($kept, [$merged]);
        $followed = ['number' => 'F-002', 'kept' => 'F-001'];

        $this->uploadFile($this->path('imports/product-vendors'), 'product-vendors.csv', "product_reference,vendor_number,vendor_code\nVIS-6,F-002,SFX-6\n", 'file', ['mode' => 'create', 'dryRun' => '0']);
        self::assertResponseIsSuccessful();
        self::assertSame([[2], [['line' => 2, 'column' => 'vendor_number', 'code' => 'vendor_followed', 'params' => $followed]]], [$this->json()['created'], $this->json()['notes']]);

        $this->uploadFile($this->path('imports/products'), 'products.csv', "reference,name,unit_code,unit_price_net,supplier_number,supplier_code\nBOU-8,BOU-8,C62,1,F-002,SFX-8\n", 'file', ['mode' => 'upsert', 'dryRun' => '0']);
        self::assertResponseIsSuccessful();
        self::assertSame([[2], [['line' => 2, 'column' => 'supplier_number', 'code' => 'vendor_followed', 'params' => $followed]]], [$this->json()['updated'], $this->json()['notes']]);

        self::assertEqualsCanonicalizing([[$this->products['BOU-8'], $kept], [$this->products['VIS-6'], $kept]], $this->supplied());
        self::assertSame([$kept, $kept], $this->em()->getConnection()->fetchFirstColumn('SELECT supplier_id::text FROM product_barcode WHERE company_id = ? ORDER BY code', [$this->companyId()]));

        $this->uploadFile($this->path('imports/vendors'), 'vendors.csv', "number,name\nF-002,Sotumag Sfax II\n", 'file', ['mode' => 'upsert', 'dryRun' => '0']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $rejected = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($rejected[0] ?? null);
        self::assertSame([2, 'number', 'vendor_merged', $followed], [$rejected[0]['line'] ?? null, $rejected[0]['column'] ?? null, $rejected[0]['code'] ?? null, $rejected[0]['params'] ?? null]);
        self::assertSame('Sotumag Sfax', $this->em()->getConnection()->fetchOne('SELECT name FROM vendor WHERE id = ?', [$merged]));
    }

    /** Two vendors registered under different numbers are two businesses, whatever their names say. */
    public function testVendorsRegisteredUnderDifferentNumbersAreNotMerged(): void
    {
        $this->signedIn();
        [$kept, $twin, $unregistered, $other] = [$this->vendorId('F-001'), $this->vendorId('F-002'), $this->vendorId('F-003'), $this->vendorId('F-004')];

        $this->postJson($this->path('record-merges'), ['subject' => 'vendor', 'keptId' => $kept, 'ids' => [$twin, $other]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['error' => 'merge_mismatch', 'column' => 'identifiers'], $this->json());
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM vendor WHERE merged_into_id IS NOT NULL'));
        // The same number, or none on one side, is no mismatch.
        $this->merged($kept, [$twin, $unregistered]);
    }

    /** A vendor merged earlier into one that is now merged away follows on to the vendor kept, and back with the undo. */
    public function testAnEarlierAliasFollowsOnToTheVendorKept(): void
    {
        $this->signedIn();
        [$kept, $middle, $first] = [$this->vendorId('F-001'), $this->vendorId('F-002'), $this->vendorId('F-003')];
        $this->merged($middle, [$first]);

        $merge = $this->merged($kept, [$middle]);

        self::assertSame($kept, $this->em()->getConnection()->fetchOne('SELECT merged_into_id::text FROM vendor WHERE id = ?', [$first]));
        $this->postJson($this->path('record-removals/'.$this->stringAt($merge, 'id').'/restore'), null);
        self::assertResponseIsSuccessful();
        self::assertSame($middle, $this->em()->getConnection()->fetchOne('SELECT merged_into_id::text FROM vendor WHERE id = ?', [$first]));
    }

    /** Before anything runs, the screen says what each vendor would hand over and what would stay with it. */
    public function testThePreviewSaysWhatMovesAndWhatStays(): void
    {
        $this->signedIn();
        $merged = $this->vendorId('F-002');
        $this->supplies('VIS-6', $merged);
        $this->aSupplierCode('BOU-8', $merged, 'SFX-88');
        $this->anExpense($merged);
        $this->anExpense($merged, record: true);
        $this->anOrder($merged);
        $this->aReceipt($merged);

        $this->getJson($this->path('record-merges/preview?subject=vendor&ids[]='.$merged));

        self::assertResponseIsSuccessful();
        self::assertSame([[
            'id' => $merged,
            'label' => 'F-002 Sotumag Sfax',
            'moves' => [['table' => 'expense', 'count' => 1], ['table' => 'product_barcode', 'count' => 1], ['table' => 'product_vendor', 'count' => 1], ['table' => 'purchase_order', 'count' => 1]],
            'stays' => [['table' => 'expense', 'count' => 1], ['table' => 'stock_movement', 'count' => 1]],
        ]], $this->json());
    }

    public function testMergingVendorsTakesWritingThemAndAProofGivenAgain(): void
    {
        $this->signedIn(prove: false);
        [$kept, $merged] = [$this->vendorId('F-001'), $this->vendorId('F-002')];

        $this->postJson($this->path('record-merges'), ['subject' => 'vendor', 'keptId' => $kept, 'ids' => [$merged]]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null);

        $this->createUser('reader@twes.local', self::PASSWORD, $this->em()->find(Company::class, $this->company->getId()) ?? $this->company, ['company.read', 'vendor.read'], 'reader');
        $this->login('reader@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('record-merges'), ['subject' => 'vendor', 'keptId' => $kept, 'ids' => [$merged]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path('record-merges/preview?subject=vendor&ids[]='.$merged));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM vendor WHERE merged_into_id IS NOT NULL'));
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $connection = $this->em()->getConnection();

        return [
            'vendors' => $connection->fetchAllAssociative('SELECT id::text, number, merged_into_id::text, is_active FROM vendor WHERE company_id = ? ORDER BY number', [$this->companyId()]),
            'supplied' => $connection->fetchAllAssociative('SELECT id::text, product_id::text, vendor_id::text, created_at FROM product_vendor WHERE company_id = ? ORDER BY id', [$this->companyId()]),
            'codes' => $connection->fetchAllAssociative('SELECT id::text, supplier_id::text FROM product_barcode WHERE company_id = ? ORDER BY id', [$this->companyId()]),
        ];
    }

    /** @return list<array{0: string, 1: string}> product and vendor of each link, in order */
    private function supplied(): array
    {
        return array_map(
            static fn (array $row): array => [\is_string($row['product_id']) ? $row['product_id'] : '', \is_string($row['vendor_id']) ? $row['vendor_id'] : ''],
            $this->em()->getConnection()->fetchAllAssociative('SELECT product_id::text AS product_id, vendor_id::text AS vendor_id FROM product_vendor WHERE company_id = ? ORDER BY product_id, vendor_id', [$this->companyId()]),
        );
    }

    /**
     * @param list<string> $merged
     *
     * @return array<string, mixed>
     */
    private function merged(string $kept, array $merged): array
    {
        $this->postJson($this->path('record-merges'), ['subject' => 'vendor', 'keptId' => $kept, 'ids' => $merged]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->json();
    }

    private function supplies(string $reference, string $vendor): void
    {
        $this->em()->getConnection()->insert('product_vendor', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $this->companyId(), 'product_id' => $this->products[$reference], 'vendor_id' => $vendor, 'created_at' => '2026-10-20 08:00:00']);
    }

    private function aSupplierCode(string $reference, string $vendor, string $code): void
    {
        $this->em()->getConnection()->insert('product_barcode', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $this->companyId(), 'product_id' => $this->products[$reference], 'supplier_id' => $vendor, 'role' => 'supplier', 'code' => $code, 'match_key' => $code, 'quantity' => 1, 'created_at' => '2026-10-20 08:00:00']);
    }

    private function anExpense(string $vendor, bool $record = false): string
    {
        $tax = $this->em()->getConnection()->fetchOne('SELECT id FROM tax_component WHERE company_id = ? AND code = ?', [$this->companyId(), 'TVA19']);
        self::assertIsString($tax);
        $this->postJson($this->path('expenses'), [
            'date' => '2026-10-20', 'reference' => null, 'description' => 'Visserie', 'vendorId' => $vendor,
            'categoryId' => $this->aCategory(), 'lines' => [['amountNet' => '10.000', 'taxComponentIds' => [$tax]]], 'notes' => null,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        if ($record) {
            $this->postJson($this->path('expenses/'.$id.'/record'), null);
            self::assertResponseIsSuccessful();
        }

        return $id;
    }

    private function aCategory(): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT id::text FROM expense_category WHERE company_id = ? LIMIT 1', [$this->companyId()]);
        if (\is_string($id)) {
            return $id;
        }
        $this->postJson($this->path('expense-categories'), ['name' => 'Achats', 'parentId' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /** A sent order is written as one: sending it prints and numbers it, which is not what is tested here. */
    private function anOrder(string $vendor, bool $sent = false): string
    {
        $this->postJson($this->path('purchase-orders'), ['vendorId' => $vendor, 'lines' => [['productId' => $this->products['VIS-6'], 'quantity' => '10']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        if ($sent) {
            $this->em()->getConnection()->update('purchase_order', ['status' => 'sent'], ['id' => $id]);
        }

        return $id;
    }

    private function aReceipt(string $vendor): string
    {
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();
        $site = $this->stringAt($this->jsonList()[0], 'id');
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->products['VIS-6'], 'locationId' => $site, 'quantity' => '3', 'vendorId' => $vendor]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->em()->getConnection()->fetchOne('SELECT id::text FROM stock_movement WHERE vendor_id = ? ORDER BY at DESC, id DESC LIMIT 1', [$vendor]);
        self::assertIsString($id);

        return $id;
    }

    private function vendorOf(string $table, string $id): ?string
    {
        $vendor = $this->em()->getConnection()->fetchOne(\sprintf('SELECT vendor_id::text FROM %s WHERE id = ?', $table), [$id]);

        return \is_string($vendor) ? $vendor : null;
    }

    private function vendorId(string $number): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT id::text FROM vendor WHERE number = ? AND company_id = ?', [$number, $this->companyId()]);
        self::assertIsString($id);

        return $id;
    }

    private function signedIn(bool $prove = true): void
    {
        $permissions = ['company.read', 'vendor.read', 'vendor.write', 'expense.read', 'expense.write', 'purchase.read', 'purchase.write', 'stock.read', 'stock.write', 'product.read', 'product.write', 'product.cost.read'];
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

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Files\Application\FileStorage;
use App\Files\Application\StoredFileMissing;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Records\Application\EndDueRemovals;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\MakesPictures;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * A product nothing names is deleted with what belongs to it (its codes, vendors, photos, places, reorder points, cost
 * history and settings), and put back with all of it; one a document, a movement, a lot or a price list names is
 * refused. Its photos' files go only when the delete is final, and only those nothing else names.
 */
final class ProductRemovalTest extends ApiTestCase
{
    use MakesPictures;

    /** The tables whose rows belong to a product, each compared whole before the delete and after the restore. */
    private const array OWNED = ['product_barcode', 'product_vendor', 'product_photo', 'product_home_location', 'product_reorder_point', 'product_cost_change'];

    private Company $company;

    private string $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $now = new \DateTimeImmutable();
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $vendor = Vendor::create($this->company, 'F-001', new VendorProfile('Sotumag'), $now);
        $this->em()->persist($vendor);
        $this->em()->flush();
        $this->vendorId = $vendor->getId()->toRfc4122();
    }

    public function testAProductNothingNamesGoesWithWhatBelongsToItAndComesBackAsItWas(): void
    {
        $this->signedIn(['product.read', 'product.write', 'stock.read']);
        $product = $this->aProduct('VIS-6', 'Vis 6 mm');
        $photo = $this->photoOf($product);
        $this->belonging($product);
        $before = $this->rowsOf($product);
        self::assertCount(\count(self::OWNED) + 2, array_filter($before), 'every table holds a row of the product, its setting included');

        $this->postJson($this->path('record-removals'), ['subject' => 'product', 'ids' => [$product]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $removal = $this->json();
        self::assertSame(['product', [['id' => $product, 'label' => 'VIS-6 Vis 6 mm']]], [$removal['subject'], $removal['items']]);
        self::assertSame([], array_filter($this->rowsOf($product)), 'nothing of the product is left');
        self::assertSame(3, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM file WHERE id IN (SELECT (snapshot->>\'small_file_id\')::uuid FROM record_removal_row UNION SELECT (snapshot->>\'large_file_id\')::uuid FROM record_removal_row UNION SELECT (snapshot->>\'original_file_id\')::uuid FROM record_removal_row)'), 'its photo\'s files are kept while the delete may be undone');

        $this->postJson($this->path('record-removals/'.$this->stringAt($removal, 'id').'/restore'), null);

        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->rowsOf($product));
        $this->getJson($this->path("products/$product/photos/$photo/content?size=small"));
        self::assertResponseIsSuccessful();
        self::assertSame(['product.deleted', 'product.restored'], $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_id = ? AND action IN ('product.deleted', 'product.restored') ORDER BY action", [$product]));
    }

    /** A movement, and a price list with no say in the product itself, both make it used: the delete names each. */
    public function testAProductAMovementOrAPriceListNamesIsRefusedAndKept(): void
    {
        $this->signedIn(['product.read', 'product.write', 'stock.read', 'stock.write']);
        $product = $this->aProduct('VIS-8', 'Vis 8 mm');
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $product, 'locationId' => $this->siteId(), 'quantity' => '3']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $list = Uuid::v7()->toRfc4122();
        $this->em()->getConnection()->insert('price_list', ['id' => $list, 'company_id' => $this->companyId(), 'name' => 'Pros', 'is_active' => 'true', 'created_at' => '2026-10-20 08:00:00', 'updated_at' => '2026-10-20 08:00:00']);
        $this->em()->getConnection()->insert('price_list_item', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $this->companyId(), 'price_list_id' => $list, 'product_id' => $product, 'min_quantity' => '1', 'unit_price_net' => '0.400']);

        $this->postJson($this->path('record-removals'), ['subject' => 'product', 'ids' => [$product]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $records = $this->arrayAt($this->json(), 'records');
        self::assertIsArray($records[0] ?? null);
        /** @var array<string, mixed> $record */
        $record = $records[0];
        self::assertEqualsCanonicalizing([
            ['table' => 'price_list_item', 'column' => 'product_id', 'count' => 1],
            ['table' => 'stock_movement', 'column' => 'product_id', 'count' => 1],
        ], $this->arrayAt($record, 'uses'));
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM product WHERE id = ?', [$product]));
    }

    /** Whoever may not write products meets the 404 a stranger would. */
    public function testDeletingAProductTakesWritingProducts(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct('VIS-10', 'Vis 10 mm');
        $this->createUser('reader@twes.local', 'password-1234', $this->em()->find(Company::class, $this->company->getId()) ?? $this->company, ['company.read', 'product.read'], 'reader');
        $this->login('reader@twes.local', 'password-1234');

        $this->postJson($this->path('record-removals'), ['subject' => 'product', 'ids' => [$product]]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM product WHERE id = ?', [$product]));
    }

    /**
     * Deleting the product took its vendor link with it, so the vendor became deletable and went too: putting the
     * product back would name a vendor that is gone. The restore refuses whole, and says so; restoring the vendor
     * first lets it through.
     */
    public function testAProductWhoseVendorWentSinceComesBackOnlyAfterTheVendor(): void
    {
        $this->signedIn(['product.read', 'product.write', 'vendor.read', 'vendor.write']);
        $product = $this->aProduct('VIS-12', 'Vis 12 mm');
        $this->em()->getConnection()->insert('product_vendor', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $this->companyId(), 'product_id' => $product, 'vendor_id' => $this->vendorId, 'created_at' => '2026-10-20 08:00:00']);
        $productRemoval = $this->removed('product', $product);
        $vendorRemoval = $this->removed('vendor', $this->vendorId);

        $this->postJson($this->path("record-removals/$productRemoval/restore"), null);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('restore_conflict', $this->json()['error']);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM product WHERE id = ?', [$product]), 'nothing came back');

        $this->postJson($this->path("record-removals/$vendorRemoval/restore"), null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->path("record-removals/$productRemoval/restore"), null);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM product_vendor WHERE product_id = ?', [$product]));
    }

    /** Once final, the photo's files and their bytes go, but a file something else still names stays. */
    public function testAFinalDeleteLetsThePhotosFilesGoThatNothingElseNames(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct('VIS-14', 'Vis 14 mm');
        $this->photoOf($product);
        $files = $this->em()->getConnection()->fetchAssociative('SELECT original_file_id, large_file_id, small_file_id FROM product_photo WHERE product_id = ?', [$product]);
        self::assertIsArray($files);
        [$original, $large, $small] = array_values(array_map(static fn (mixed $id): string => \is_string($id) ? $id : self::fail('A file id reads as text.'), $files));
        // Something else names the original: it must outlive the delete.
        $this->em()->getConnection()->insert('attachment', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $this->companyId(), 'file_id' => $original, 'entity_type' => 'expense', 'entity_id' => Uuid::v7()->toRfc4122(), 'created_at' => '2026-10-20 08:00:00']);
        $keys = $this->em()->getConnection()->fetchAllKeyValue('SELECT id, storage_key FROM file WHERE id IN (?, ?, ?)', [$original, $large, $small]);
        $this->removed('product', $product);

        Clock::set(new MockClock(new \DateTimeImmutable('+24 hours +1 minute')));
        try {
            static::getContainer()->get(EndDueRemovals::class)->handle();
            static::getContainer()->get(EndDueRemovals::class)->handle();
        } finally {
            Clock::set(new NativeClock());
        }

        self::assertSame([$original], $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM file WHERE id IN (?, ?, ?)', [$original, $large, $small]));
        $storage = static::getContainer()->get(FileStorage::class);
        self::assertNotSame('', $storage->read($this->text($keys[$original] ?? null)));
        foreach ([$large, $small] as $gone) {
            try {
                $storage->read($this->text($keys[$gone] ?? null));
                self::fail('The bytes of a file nothing names outlived the delete.');
            } catch (StoredFileMissing) {
            }
        }
    }

    /** A file the copy of a delete still pending names may come back with it: another delete ending leaves it. */
    public function testAFileAnotherPendingDeleteStillNamesStays(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $first = $this->aProduct('VIS-16', 'Vis 16 mm');
        $second = $this->aProduct('VIS-18', 'Vis 18 mm');
        $this->photoOf($first);
        $photo = $this->em()->getConnection()->fetchAssociative('SELECT * FROM product_photo WHERE product_id = ?', [$first]);
        self::assertIsArray($photo);
        // The second product shows the same picture: its photo names the very same files.
        $this->em()->getConnection()->insert('product_photo', [...$photo, 'id' => Uuid::v7()->toRfc4122(), 'product_id' => $second]);
        // Both deletes are asked at the real time, the first then made due: a request under a moved clock outlives the
        // session's idle limit and is signed out.
        $ending = $this->removed('product', $first);
        $this->removed('product', $second);
        $this->em()->getConnection()->executeStatement("UPDATE record_removal SET effective_at = effective_at - interval '25 hours' WHERE id = ?", [$ending]);
        self::assertSame(1, static::getContainer()->get(EndDueRemovals::class)->handle());

        self::assertSame(3, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM file WHERE id IN (?, ?, ?)', [$photo['original_file_id'], $photo['large_file_id'], $photo['small_file_id']]));
    }

    /** A setting kept at another level is no product's, whatever its level id says. */
    public function testOnlyTheSettingsKeptAtTheProductsLevelGoWithIt(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct('VIS-20', 'Vis 20 mm');
        $this->em()->getConnection()->insert('setting', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $this->companyId(), 'level' => 'customer', 'level_id' => $product, 'key' => 'article.stock_tracking', 'value' => 'false', 'updated_at' => '2026-10-20 08:00:00']);

        $this->removed('product', $product);

        self::assertSame(1, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM setting WHERE level = 'customer' AND level_id = ?", [$product]));
    }

    /**
     * A row of each table that belongs to a product, written as the screens would leave it, and a setting kept at the
     * product's level.
     */
    private function belonging(string $product): void
    {
        $connection = $this->em()->getConnection();
        $company = $this->companyId();
        $establishment = $connection->fetchOne('SELECT id FROM establishment WHERE company_id = ?', [$company]);
        self::assertIsString($establishment);
        $at = '2026-10-20 08:00:00';
        $connection->insert('product_barcode', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $company, 'product_id' => $product, 'role' => 'supplier', 'code' => 'SOT-0006', 'match_key' => 'SOT-0006', 'quantity' => 1, 'created_at' => $at, 'supplier_id' => $this->vendorId]);
        $connection->insert('product_vendor', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $company, 'product_id' => $product, 'vendor_id' => $this->vendorId, 'created_at' => $at]);
        $connection->insert('product_home_location', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $company, 'product_id' => $product, 'establishment_id' => $establishment, 'location_id' => $this->siteId(), 'position' => 0, 'created_at' => $at, 'updated_at' => $at]);
        $connection->insert('product_reorder_point', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $company, 'product_id' => $product, 'establishment_id' => $establishment, 'quantity' => '5.000', 'created_at' => $at, 'updated_at' => $at]);
        $connection->insert('product_cost_change', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $company, 'product_id' => $product, 'old_cost' => null, 'new_cost' => '0.300', 'source' => 'edit', 'at' => $at]);
        $connection->insert('setting', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $company, 'level' => 'product', 'level_id' => $product, 'key' => 'article.stock_tracking', 'value' => 'false', 'updated_at' => $at]);
    }

    /**
     * Every row of the product and of each table that belongs to it, as stored, by table.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function rowsOf(string $product): array
    {
        $connection = $this->em()->getConnection();
        $rows = ['product' => $connection->fetchAllAssociative('SELECT * FROM product WHERE id = ?', [$product])];
        foreach (self::OWNED as $table) {
            $rows[$table] = $connection->fetchAllAssociative("SELECT * FROM $table WHERE product_id = ? ORDER BY id", [$product]);
        }
        $rows['setting'] = $connection->fetchAllAssociative("SELECT * FROM setting WHERE level = 'product' AND level_id = ? ORDER BY id", [$product]);

        return $rows;
    }

    private function photoOf(string $product): string
    {
        $this->uploadFile($this->path("products/$product/photos"), 'photo.jpg', self::jpeg(400, 300));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function aProduct(string $reference, string $name): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $this->postJson($this->path('products'), [
            'reference' => $reference, 'name' => $name, 'description' => null, 'kind' => 'goods',
            'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => '0.500', 'costPrice' => null, 'categoryId' => null,
            'defaultTaxComponentIds' => [], 'customFields' => [], 'isActive' => true,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function removed(string $subject, string $id): string
    {
        $this->postJson($this->path('record-removals'), ['subject' => $subject, 'ids' => [$id]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function siteId(): string
    {
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();

        return $this->stringAt($this->jsonList()[0], 'id');
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : self::fail('A storage key reads as text.');
    }

    private function companyId(): string
    {
        return $this->company->getId()->toRfc4122();
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('shop@twes.local', 'password-1234', $this->company, ['company.read', ...$permissions], 'member');
        $this->login('shop@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(string $resource): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource;
    }
}

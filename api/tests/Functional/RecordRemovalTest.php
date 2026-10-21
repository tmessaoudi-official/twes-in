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
use App\ModuleRegistry\Domain\Model\ModuleState;
use App\Records\Application\EndDueRemovals;
use App\Records\Application\ReadRemovals;
use App\Records\Application\RestoreRecords;
use App\Records\Domain\Exception\RemovalNoLongerPending;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * A record nothing names is deleted, kept 24 hours on « Récemment supprimés » and restored from there; one a row still
 * names, through a foreign key or not, is refused, saying what names it. A vendor first.
 */
final class RecordRemovalTest extends ApiTestCase
{
    private Company $company;

    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $product = Product::create($this->company, 'VIS-6', new ProductDetails('Vis 6 mm', null, ProductKind::Goods, '0.500'), $piece, null, [], $now);
        $this->em()->persist($product);
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        foreach (['F-001' => 'Sotumag', 'F-002' => 'Visserie du Sud', 'F-003' => 'Quincaillerie Sfax'] as $number => $name) {
            $this->em()->persist(Vendor::create($this->company, $number, new VendorProfile($name), $now));
        }
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();
    }

    public function testAVendorNothingNamesIsDeletedThenRestoredAsItWas(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $vendor = $this->vendorId('F-001');
        $before = $this->em()->getConnection()->fetchAssociative('SELECT * FROM vendor WHERE id = ?', [$vendor]);

        $this->postJson($this->path('record-removals'), ['subject' => 'vendor', 'ids' => [$vendor]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $removal = $this->json();
        self::assertSame(['vendor', 'pending', [['id' => $vendor, 'label' => 'F-001 Sotumag']]], [$removal['subject'], $removal['state'], $removal['items']]);
        self::assertFalse($this->em()->getConnection()->fetchOne('SELECT 1 FROM vendor WHERE id = ?', [$vendor]));
        $this->getJson($this->path('vendors/'.$vendor));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->postJson($this->path('record-removals/'.$this->stringAt($removal, 'id').'/restore'), null);

        self::assertResponseIsSuccessful();
        self::assertSame('restored', $this->json()['state']);
        self::assertSame($before, $this->em()->getConnection()->fetchAssociative('SELECT * FROM vendor WHERE id = ?', [$vendor]));
        self::assertSame(['vendor.deleted', 'vendor.restored'], $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_id = ? AND action IN ('vendor.deleted', 'vendor.restored') ORDER BY action", [$vendor]));
    }

    /**
     * A second « Rétablir » (a double click, a retry after a lost answer) finds the records back already: it says so,
     * never that the day is over, and puts nothing back twice.
     */
    public function testARestoreSentAgainSaysTheRecordsAreBackAlready(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $removal = $this->removed('F-001');
        $this->postJson($this->path('record-removals/'.$removal.'/restore'), null);
        self::assertResponseIsSuccessful();

        $this->postJson($this->path('record-removals/'.$removal.'/restore'), null);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('removal_restored', $this->json()['error']);
        self::assertSame(1, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'vendor.restored' AND entity_id = ?", [$this->vendorId('F-001')]));
    }

    /**
     * A movement's foreign key would empty its vendor and let the delete through: the refusal comes before any delete,
     * from the declaration, and leaves the movement naming its vendor.
     */
    public function testAVendorAMovementNamesIsRefusedAndKept(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write', 'stock.read', 'stock.write']);
        $vendor = $this->vendorId('F-002');
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->productId, 'locationId' => $this->siteId(), 'quantity' => '3', 'vendorId' => $vendor]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson($this->path('record-removals'), ['subject' => 'vendor', 'ids' => [$vendor]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['error' => 'record_used', 'records' => [['id' => $vendor, 'label' => 'F-002 Visserie du Sud', 'uses' => [['table' => 'stock_movement', 'column' => 'vendor_id', 'count' => 1]]]]], $this->json());
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM stock_movement WHERE vendor_id = ?', [$vendor]));
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM vendor WHERE id = ?', [$vendor]));
    }

    /** An order names its vendor with no foreign key: only the declaration knows, and the vendor is kept. */
    public function testAVendorAnOrderNamesIsRefusedThoughNoForeignKeyBacksIt(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write', 'purchase.read', 'purchase.write']);
        $vendor = $this->vendorId('F-003');
        $this->postJson($this->path('purchase-orders'), ['vendorId' => $vendor, 'lines' => [['productId' => $this->productId, 'quantity' => '10']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson($this->path('record-removals'), ['subject' => 'vendor', 'ids' => [$vendor]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $records = $this->arrayAt($this->json(), 'records');
        self::assertIsArray($records[0] ?? null);
        /** @var array<string, mixed> $record */
        $record = $records[0];
        self::assertSame([['table' => 'purchase_order', 'column' => 'vendor_id', 'count' => 1]], $this->arrayAt($record, 'uses'));
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM vendor WHERE id = ?', [$vendor]));
    }

    /** Several at once go all together, or none goes, the refusal naming only those in use. */
    public function testSeveralGoTogetherOrNoneGoes(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write', 'stock.read', 'stock.write']);
        [$free, $used, $other] = [$this->vendorId('F-001'), $this->vendorId('F-002'), $this->vendorId('F-003')];
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->productId, 'locationId' => $this->siteId(), 'quantity' => '1', 'vendorId' => $used]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson($this->path('record-removals'), ['subject' => 'vendor', 'ids' => [$free, $used]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame([$used], array_column($this->arrayAt($this->json(), 'records'), 'id'));
        self::assertSame(3, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM vendor'));

        $this->postJson($this->path('record-removals'), ['subject' => 'vendor', 'ids' => [$free, $other]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['F-001 Sotumag', 'F-003 Quincaillerie Sfax'], array_column($this->arrayAt($this->json(), 'items'), 'label'));
        self::assertSame([$used], $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM vendor'));
    }

    /** Whoever may not write vendors meets the 404 a stranger would. */
    public function testDeletingAVendorTakesWritingVendors(): void
    {
        $this->signedIn(['vendor.read']);

        $this->postJson($this->path('record-removals'), ['subject' => 'vendor', 'ids' => [$this->vendorId('F-001')]]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(3, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM vendor'));
    }

    /** Another company's vendor, an unknown subject and a vendor of a company that keeps none answer as a stranger would. */
    public function testWhatTheCompanyDoesNotHaveIsNotFound(): void
    {
        $other = $this->createCompany('Ailleurs');
        $theirs = Vendor::create($other, 'F-001', new VendorProfile('Autre'), new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->flush();
        $this->signedIn(['vendor.read', 'vendor.write']);

        $this->postJson($this->path('record-removals'), ['subject' => 'vendor', 'ids' => [$this->vendorId('F-001'), $theirs->getId()->toRfc4122()]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path('record-removals'), ['subject' => 'invoice', 'ids' => [$this->vendorId('F-001')]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path('record-removals'), ['subject' => 'vendor', 'ids' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->em()->persist(ModuleState::of($this->em()->find(Company::class, $this->company->getId()) ?? $this->company, 'vendors', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->postJson($this->path('record-removals'), ['subject' => 'vendor', 'ids' => [$this->vendorId('F-001')]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(4, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM vendor'));
    }

    /** A vendor given the number of the one deleted meanwhile stands in its way: the restore says so and changes nothing. */
    public function testARestoreSomethingMadeSinceStandsInTheWayOfIsRefused(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $removal = $this->removed('F-001');
        $this->postJson($this->path('vendors'), ['number' => 'F-001', 'name' => 'Nouveau Sotumag']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson($this->path('record-removals/'.$removal.'/restore'), null);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('restore_conflict', $this->json()['error']);
        self::assertSame(['Nouveau Sotumag'], $this->em()->getConnection()->fetchFirstColumn("SELECT name FROM vendor WHERE number = 'F-001'"));
        $this->getJson($this->path('record-removals'));
        self::assertSame(['pending'], array_column($this->jsonList(), 'state'));
    }

    /** After 24 hours a delete is final: its copy goes, it leaves the page, and it can no longer be restored. */
    public function testADeleteIsFinalAfterADay(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $removal = $this->removed('F-001');
        $this->getJson($this->path('record-removals'));
        self::assertSame([[$removal, ['F-001 Sotumag']]], array_map(fn (array $row): array => [$row['id'], array_column($this->arrayAt($row, 'items'), 'label')], $this->jsonList()));

        Clock::set(new MockClock(new \DateTimeImmutable('+24 hours +1 minute')));
        try {
            // Its day is over before the worker passes: no longer offered, and refused. The session has ended by then,
            // so the use cases answer.
            $company = $this->em()->find(Company::class, $this->company->getId()) ?? $this->company;
            self::assertSame([], static::getContainer()->get(ReadRemovals::class)->pending($company, ['vendor']));
            try {
                static::getContainer()->get(RestoreRecords::class)->handle($company, Uuid::fromString($removal), null);
                self::fail('A delete past its day was restored.');
            } catch (RemovalNoLongerPending) {
            }
            self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM record_removal_row WHERE removal_id = ?', [$removal]));

            static::getContainer()->get(EndDueRemovals::class)->handle();
            static::getContainer()->get(EndDueRemovals::class)->handle();
        } finally {
            Clock::set(new NativeClock());
        }

        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM record_removal_row'));
        self::assertSame('final', $this->em()->getConnection()->fetchOne('SELECT state FROM record_removal WHERE id = ?', [$removal]));
        $this->getJson($this->path('record-removals'));
        self::assertSame([], $this->jsonList());
        $this->postJson($this->path('record-removals/'.$removal.'/restore'), null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('removal_final', $this->json()['error']);
    }

    /** The page lists only what the person may write: a removal of vendors stays out of reach of whoever may not. */
    public function testThePageShowsOnlyWhatThePersonMayRestore(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $removal = $this->removed('F-001');
        // The kernel rebooted with the request: the company is found again.
        $this->createUser('reader@twes.local', 'password-1234', $this->em()->find(Company::class, $this->company->getId()) ?? $this->company, ['company.read', 'vendor.read'], 'reader');
        $this->login('reader@twes.local', 'password-1234');

        $this->getJson($this->path('record-removals'));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList());
        $this->postJson($this->path('record-removals/'.$removal.'/restore'), null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('pending', $this->em()->getConnection()->fetchOne('SELECT state FROM record_removal WHERE id = ?', [$removal]));
    }

    private function removed(string $number): string
    {
        $this->postJson($this->path('record-removals'), ['subject' => 'vendor', 'ids' => [$this->vendorId($number)]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function vendorId(string $number): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT id FROM vendor WHERE number = ? AND company_id = ?', [$number, $this->company->getId()->toRfc4122()]);
        self::assertIsString($id);

        return $id;
    }

    private function siteId(): string
    {
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();

        return $this->stringAt($this->jsonList()[0], 'id');
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

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Inventory\Application\KeepProductHomes;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Products\Domain\Barcode;
use App\Module\Products\Domain\BarcodeLine;
use App\Module\Products\Domain\BarcodeRole;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\EstablishmentRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * The stock a company already has, imported from a file (docs/SPEC.md § 8 row 59, § 7 2026-10-09 10:40 (6)). A row
 * names a product, by its reference or its unit code, and either ADDS goods (`stock_add`, a receipt) or COUNTS them
 * (`stock_count`, what is there, so the same file twice leaves the same stock rather than twice as much).
 *
 * A row is found again by its product AND its place, however it names them: only the same pair twice is a duplicate.
 */
final class OpeningStockImportTest extends ApiTestCase
{
    private const string HEADER = 'reference,barcode,location_code,stock_add,stock_count';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $units = static::getContainer()->get(UnitRepository::class);
        $now = new \DateTimeImmutable();
        // A quincaillerie counts screws one by one and wire by the kilo: the unit decides how fine a count may be.
        foreach ([['VIS-6X40', 'Vis 6x40', ProductKind::Goods, 'C62', '0.2000'], ['FIL-2', 'Fil de fer 2mm', ProductKind::Goods, 'KGM', null], ['MO-TOUR', 'Tournage', ProductKind::Service, 'C62', null], ['COL-01', 'Colle', ProductKind::Goods, 'C62', null]] as [$reference, $name, $kind, $unitCode, $cost]) {
            $unit = $units->ofCodeInCompany($unitCode, $this->company->getId());
            self::assertNotNull($unit, $unitCode);
            $product = Product::create($this->company, $reference, new ProductDetails($name, null, $kind, '1.000', $cost), $unit, null, [], $now);
            if ('COL-01' === $reference) {
                $product->track(ProductTracking::Lot, $now);
            }
            if ('VIS-6X40' === $reference) {
                $product->replaceBarcodes([new BarcodeLine(BarcodeRole::Unit, new Barcode('6191234567897'), 1)], $now);
            }
            $this->em()->persist($product);
        }
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        // A zone under the establishment's default location, so a file names somewhere other than the site itself.
        $establishment = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0];
        $default = $this->manageLocations()->defaultOf($establishment);
        $this->locations()->save(StockLocation::create($establishment, $default, StockLocationKind::Zone, 'Z1', 'Zone froide', $now));
        $this->em()->flush();
    }

    public function testAPreviewSaysWhatEachRowDoesToTheStockAndStoresNothing(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import($this->twoCounts(), dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => false, 'created' => [2, 3], 'updated' => [], 'rejected' => [], 'notes' => [
            ['line' => 2, 'column' => 'stock_count', 'code' => 'stock_change', 'params' => ['location' => '000', 'before' => '0', 'after' => '120']],
            ['line' => 3, 'column' => 'stock_count', 'code' => 'stock_change', 'params' => ['location' => 'Z1', 'before' => '0.000', 'after' => '7.500']],
        ], 'alreadyImportedAt' => null], $this->json());
        self::assertSame(0, $this->movements());
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM import_run'), 'a preview is not an import');
    }

    public function testEachRowCountsItsProductWhereItSitsAndEveryMovementIsTheImports(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import($this->twoCounts());

        self::assertResponseIsSuccessful();
        self::assertSame([true, [2, 3]], [$this->json()['committed'] ?? null, $this->json()['created'] ?? null]);
        self::assertSame('120.000', $this->onHand('VIS-6X40', '000'));
        self::assertSame('7.500', $this->onHand('FIL-2', 'Z1'), 'a decimal comma is read as a point, and a kilo counts with decimals');
        $run = $this->em()->getConnection()->fetchAssociative('SELECT id, subject, content_hash, mode, created, updated FROM import_run WHERE company_id = ?', [$this->company->getId()->toRfc4122()]);
        self::assertIsArray($run);
        self::assertSame(['opening-stock', hash('sha256', $this->twoCounts()), 'create', 2, 0], [$run['subject'], $run['content_hash'], $run['mode'], $run['created'], $run['updated']]);
        self::assertSame(2, $this->number('SELECT COUNT(*) FROM stock_movement WHERE import_run_id = ?', [$run['id']]), 'each movement reads as the import’s');
    }

    public function testTheSameFileIsToldItWasAlreadyImportedBeforeItIsConfirmed(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->import($this->twoCounts());
        self::assertArrayHasKey('alreadyImportedAt', $this->json());
        self::assertNull($this->json()['alreadyImportedAt']);

        $this->import($this->twoCounts(), mode: 'upsert', dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertIsString($this->json()['alreadyImportedAt'] ?? null);
        $this->import(self::HEADER."\nVIS-6X40,,000,,121\n", mode: 'upsert', dryRun: true);
        self::assertArrayHasKey('alreadyImportedAt', $this->json());
        self::assertNull($this->json()['alreadyImportedAt'], 'another file');
    }

    public function testAnAdditionIsAReceiptAtTheProductsCostAndAddsToWhatIsThere(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->import(self::HEADER."\nVIS-6X40,,000,,100\n");
        self::assertResponseIsSuccessful();

        $this->import(self::HEADER."\n,6191234567897,000,24,\n", mode: 'upsert');

        self::assertResponseIsSuccessful();
        self::assertSame([[], [2]], [$this->json()['created'] ?? null, $this->json()['updated'] ?? null]);
        self::assertSame([['line' => 2, 'column' => 'stock_add', 'code' => 'stock_change', 'params' => ['location' => '000', 'before' => '100', 'after' => '124']]], $this->json()['notes'] ?? null);
        self::assertSame('124.000', $this->onHand('VIS-6X40', '000'));
        $receipt = $this->em()->getConnection()->fetchAssociative("SELECT unit_cost, cost_typed FROM stock_movement WHERE source_type = 'receipt'");
        self::assertSame(['unit_cost' => '0.2000', 'cost_typed' => true], $receipt, 'the product’s own cost, so the valuation holds');
    }

    public function testACostReaderMayGiveTheAdditionItsOwnCost(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.cost.read']);

        $this->import("reference,location_code,stock_add,unit_cost\nVIS-6X40,000,10,\"0,25\"\n");

        self::assertResponseIsSuccessful();
        self::assertSame('0.2500', $this->em()->getConnection()->fetchOne("SELECT unit_cost FROM stock_movement WHERE source_type = 'receipt'"));
    }

    public function testSomebodyWhoCannotReadCostsIsNotAskedForOne(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import("reference,location_code,stock_add,unit_cost\nVIS-6X40,000,10,0.25\n", dryRun: true);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['unit_cost'], $this->json()['columns'] ?? null);
    }

    /** The point of the pair: one product at two places is two things; the same pair, however named, is one thing twice. */
    public function testTheSamePairTwiceIsADuplicateHoweverEachRowNamesIt(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import(self::HEADER."\nVIS-6X40,,000,,1\nVIS-6X40,,Z1,,2\n,6191234567897,000,3,\n", dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame([2, 3], $this->json()['created'] ?? null);
        self::assertSame([[4, 'barcode', 'duplicate_in_file', ['line' => 2]]], $this->rejected());
    }

    public function testWithoutALocationTheQuantityGoesToTheProductsHomeElseTheOnlyEstablishmentsPlace(): void
    {
        $zone = $this->locations()->ofCodeInCompany('Z1', $this->company->getId())[0];
        $screw = $this->productId('VIS-6X40');
        static::getContainer()->get(KeepProductHomes::class)->set($this->company, $screw, $zone->getId(), null);
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import(self::HEADER."\nVIS-6X40,,,,5\nFIL-2,,,,2\n");

        self::assertResponseIsSuccessful();
        self::assertSame(['5.000', '2.000'], [$this->onHand('VIS-6X40', 'Z1'), $this->onHand('FIL-2', '000')]);
    }

    public function testACompanyWithSeveralEstablishmentsMustSayWhichOne(): void
    {
        $this->secondEstablishment();
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import(self::HEADER."\nFIL-2,,,,2\n", dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame([[2, 'location_code', 'location_needed', []]], $this->rejected());
    }

    public function testEveryRejectedRowCarriesACodeAndItsParameters(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import(
            self::HEADER
            ."\n,,000,,1"
            ."\nFIL-2,,000,,"
            ."\nVIS-6X40,,000,1,1"
            ."\nINCONNU,,000,,1"
            ."\n,0000000000000,000,,1"
            ."\nFIL-2,6191234567897,000,,1"
            ."\nVIS-6X40,,ZZZ,,1"
            ."\nMO-TOUR,,000,,1"
            ."\nVIS-6X40,,Z1,,deux"
            ."\nCOL-01,,000,,4"
            ."\nFIL-2,,Z1,-3,\n",
            dryRun: true,
        );

        self::assertResponseIsSuccessful();
        self::assertSame([
            [2, 'reference', 'value_required', []],
            [3, 'stock_count', 'quantity_required', []],
            [4, 'stock_count', 'stock_add_and_count', []],
            [5, 'reference', 'unknown_product', ['reference' => 'INCONNU']],
            [6, 'barcode', 'unknown_barcode', ['code' => '0000000000000']],
            [7, 'barcode', 'barcode_not_the_reference', ['code' => '6191234567897', 'reference' => 'FIL-2']],
            [8, 'location_code', 'unknown_location', ['code' => 'ZZZ']],
            [9, 'reference', 'not_stocked', ['reference' => 'MO-TOUR']],
            [10, 'stock_count', 'invalid_quantity', []],
            [11, 'reference', 'lot_tracked', ['reference' => 'COL-01']],
            [12, 'stock_add', 'invalid_quantity', []],
        ], $this->rejected());
    }

    /** A code names one place per establishment, so a company with two sites using the same code is asked, not guessed. */
    public function testALocationCodeTwoEstablishmentsShareIsRefusedRatherThanGuessed(): void
    {
        $now = new \DateTimeImmutable();
        $second = $this->secondEstablishment();
        $this->locations()->save(StockLocation::create($second, $this->manageLocations()->defaultOf($second), StockLocationKind::Zone, 'Z1', 'Zone froide', $now));
        $this->em()->flush();
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import(self::HEADER."\nVIS-6X40,,Z1,,1\n", dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame([[2, 'location_code', 'ambiguous_location', ['code' => 'Z1']]], $this->rejected());
    }

    public function testCreateModeRefusesAPlaceAlreadyStockedAndUpsertCountsItAgainToTheSameStock(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->import($this->twoCounts());
        self::assertResponseIsSuccessful();

        $this->import($this->twoCounts(), dryRun: true);
        self::assertSame([[2, 'reference', 'already_counted', []], [3, 'reference', 'already_counted', []]], $this->rejected());
        $this->import(self::HEADER."\nVIS-6X40,,000,5,\n", dryRun: true);
        self::assertSame([[2, 'reference', 'already_stocked', ['location' => '000']]], $this->rejected(), 'an addition file run twice in create mode adds nothing twice');

        $this->import($this->twoCounts(), mode: 'upsert');

        self::assertResponseIsSuccessful();
        self::assertSame([[], [2, 3]], [$this->json()['created'] ?? null, $this->json()['updated'] ?? null]);
        self::assertSame(['120.000', '7.500'], [$this->onHand('VIS-6X40', '000'), $this->onHand('FIL-2', 'Z1')], 'a count says what is there, so the same file twice leaves the same stock');
    }

    /** A count taken before a receipt or a sale, imported after it, would erase it: it is asked to be ticked first. */
    public function testACountWhereGoodsMovedSinceTheLastCountWaitsForRecompter(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->import($this->twoCounts());
        $zero = $this->locations()->ofCodeInCompany('000', $this->company->getId())[0];
        static::getContainer()->get(KeepStock::class)->receive($this->company, $this->productId('VIS-6X40'), $zero->getId(), '10', null);

        $this->import(self::HEADER."\nVIS-6X40,,000,,118\nFIL-2,,Z1,,7\n", mode: 'upsert', dryRun: true);
        self::assertSame([[2, 'stock_count', 'moved_since_count', ['location' => '000']]], $this->rejected(), 'the wire was not touched since its count');

        $this->import(self::HEADER."\nVIS-6X40,,000,,118\nFIL-2,,Z1,,7\n", mode: 'upsert', switches: ['recount']);

        self::assertResponseIsSuccessful();
        self::assertSame(['118.000', '7.000'], [$this->onHand('VIS-6X40', '000'), $this->onHand('FIL-2', 'Z1')]);
    }

    public function testTheGuideOffersRecompter(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);

        $this->client->request('GET', '/api/companies/'.$this->company->getId()->toRfc4122().'/imports/opening-stock', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertSame([['key' => 'recount', 'labelKey' => 'import.stock.recount', 'noteKey' => 'import.stock.recount_note']], $this->json()['switches'] ?? null);
    }

    /** The other stock keepers hear once that a file counted differences, not once per row (§ 7 2026-10-09 10:40 (10)). */
    public function testAFileTellsTheOtherKeepersOnceForAllItsDifferences(): void
    {
        $this->createUser('keeper@twes.local', 'password-1234', $this->company, ['stock.read', 'stock.write'], 'keeper');
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import($this->twoCounts());

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM inbox_item WHERE type = 'stock.count_difference'"));
        $told = $this->em()->getConnection()->fetchAllAssociative("SELECT payload FROM inbox_item WHERE type = 'stock.imported'");
        self::assertCount(1, $told, 'the keeper, once; not the person who imported');
        $payload = $told[0]['payload'];
        self::assertIsString($payload);
        $said = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($said);
        self::assertSame(['counted' => 2, 'differences' => 2], array_intersect_key($said, ['counted' => 0, 'differences' => 0]));
    }

    public function testSomebodyWhoCannotWriteStockIsAnsweredAsAStranger(): void
    {
        $this->signedIn(['stock.read']);

        $this->import($this->twoCounts(), dryRun: true);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherCompanysFileIsAnsweredAsAStranger(): void
    {
        $other = $this->createCompany('Ailleurs');
        $this->signedIn(['stock.read', 'stock.write']);

        $this->uploadFile('/api/companies/'.$other->getId()->toRfc4122().'/imports/opening-stock', 'stock.csv', $this->twoCounts(), 'file', ['mode' => 'create', 'dryRun' => '1']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function twoCounts(): string
    {
        return self::HEADER."\nVIS-6X40,,000,,120\nFIL-2,,Z1,,\"7,5\"\n";
    }

    /** @param list<string> $switches */
    private function import(string $contents, string $mode = 'create', bool $dryRun = false, array $switches = []): void
    {
        $this->uploadFile(
            '/api/companies/'.$this->company->getId()->toRfc4122().'/imports/opening-stock',
            'stock.csv',
            $contents,
            'file',
            ['mode' => $mode, 'dryRun' => $dryRun ? '1' : '0', 'switches' => $switches],
        );
    }

    private function secondEstablishment(): Establishment
    {
        $second = Establishment::create($this->company, 'LYON', 'Lyon', false, new \DateTimeImmutable());
        $this->em()->persist($second);
        $this->em()->flush();

        return $second;
    }

    /**
     * Each rejected row as the screen reads it: its line, the column at fault, the code it translates and its words.
     *
     * @return list<array<int, mixed>>
     */
    private function rejected(): array
    {
        $rows = [];
        foreach ($this->arrayAt($this->json(), 'rejected') as $row) {
            $rows[] = \is_array($row) ? [$row['line'] ?? null, $row['column'] ?? null, $row['code'] ?? null, $row['params'] ?? null] : [];
        }

        return $rows;
    }

    private function productId(string $reference): \Symfony\Component\Uid\Uuid
    {
        $product = $this->em()->getRepository(Product::class)->findOneBy(['reference' => $reference, 'company' => $this->company->getId()]);
        self::assertInstanceOf(Product::class, $product);

        return $product->getId();
    }

    private function onHand(string $reference, string $locationCode): string
    {
        $sum = $this->em()->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(m.quantity), 0) FROM stock_movement m'
            .' JOIN product p ON p.id = m.product_id JOIN stock_location l ON l.id = m.location_id'
            .' WHERE p.reference = ? AND l.code = ? AND p.company_id = ?',
            [$reference, $locationCode, $this->company->getId()->toRfc4122()],
        );
        self::assertIsNumeric($sum);

        return number_format((float) $sum, 3, '.', '');
    }

    private function movements(): int
    {
        return $this->number('SELECT COUNT(*) FROM stock_movement m JOIN product p ON p.id = m.product_id WHERE p.company_id = ?', [$this->company->getId()->toRfc4122()]);
    }

    /** @param list<mixed> $parameters */
    private function number(string $sql, array $parameters = []): int
    {
        $count = $this->em()->getConnection()->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function manageLocations(): ManageStockLocations
    {
        $manage = static::getContainer()->get(ManageStockLocations::class);
        self::assertInstanceOf(ManageStockLocations::class, $manage);

        return $manage;
    }

    private function locations(): StockLocationRepository
    {
        $locations = static::getContainer()->get(StockLocationRepository::class);
        self::assertInstanceOf(StockLocationRepository::class, $locations);

        return $locations;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('shop@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('shop@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}

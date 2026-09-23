<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Module\Inventory\Domain\StockLocationRepository;
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
 * The stock a company already has, imported from a file (docs/SPEC.md § 8 row 59). It is the first subject a row is
 * found again by a PAIR — a product AND the place it sits in — so the same product appears on as many lines as it has
 * locations, and only the same pair twice is a duplicate.
 *
 * A row is a COUNT, not a receipt: it says what is there, so importing the same file twice leaves the same stock
 * rather than twice as much.
 */
final class OpeningStockImportTest extends ApiTestCase
{
    private const string HEADER = 'reference,location_code,quantity';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $units = static::getContainer()->get(UnitRepository::class);
        $now = new \DateTimeImmutable();
        // A quincaillerie counts screws one by one and wire by the kilo: the unit decides how fine a count may be.
        foreach ([['VIS-6X40', 'Vis 6x40', ProductKind::Goods, 'C62'], ['FIL-2', 'Fil de fer 2mm', ProductKind::Goods, 'KGM'], ['MO-TOUR', 'Tournage', ProductKind::Service, 'C62'], ['COL-01', 'Colle', ProductKind::Goods, 'C62']] as [$reference, $name, $kind, $unitCode]) {
            $unit = $units->ofCodeInCompany($unitCode, $this->company->getId());
            self::assertNotNull($unit, $unitCode);
            $product = Product::create($this->company, $reference, new ProductDetails($name, null, $kind, '1.000'), $unit, null, [], $now);
            if ('COL-01' === $reference) {
                $product->track(ProductTracking::Lot, $now);
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

    public function testAPreviewSaysWhatWouldHappenAndStoresNothing(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import($this->twoLines(), dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => false, 'created' => [2, 3], 'updated' => [], 'rejected' => []], $this->json());
        self::assertSame(0, $this->movements());
    }

    public function testEachRowCountsItsProductWhereItSits(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import($this->twoLines());

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [2, 3], 'updated' => [], 'rejected' => []], $this->json());
        self::assertSame('120.000', $this->onHand('VIS-6X40', '000'));
        self::assertSame('7.500', $this->onHand('FIL-2', 'Z1'), 'a decimal comma is read as a point, and a kilo counts with decimals');
        self::assertSame(2, $this->movements());
    }

    /** The point of the pair: one product on two lines is two things, the same pair on two lines is one thing twice. */
    public function testTheSamePairTwiceIsADuplicateAndTheSameProductElsewhereIsNot(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import(self::HEADER."\nVIS-6X40,000,1\nVIS-6X40,Z1,2\nVIS-6X40,000,3\n", dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame([2, 3], $this->json()['created'] ?? null);
        self::assertSame([[4, 'reference', 'duplicate_in_file', ['line' => 2]]], $this->rejected());
    }

    public function testEveryRejectedRowCarriesACodeAndItsParameters(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import(
            self::HEADER
            ."\n,000,1"
            ."\nVIS-6X40,,1"
            ."\nVIS-6X40,000,"
            ."\nINCONNU,000,1"
            ."\nVIS-6X40,ZZZ,1"
            ."\nMO-TOUR,000,1"
            ."\nVIS-6X40,Z1,deux"
            ."\nCOL-01,000,4\n",
            dryRun: true,
        );

        self::assertResponseIsSuccessful();
        self::assertSame([
            [2, 'reference', 'value_required', []],
            [3, 'location_code', 'value_required', []],
            [4, 'quantity', 'value_required', []],
            [5, 'reference', 'unknown_product', ['reference' => 'INCONNU']],
            [6, 'location_code', 'unknown_location', ['code' => 'ZZZ']],
            [7, 'reference', 'not_stocked', []],
            [8, 'quantity', 'invalid_quantity', []],
            [9, 'reference', 'lot_tracked', ['reference' => 'COL-01']],
        ], $this->rejected());
    }

    /** A code names one place per establishment, so a company with two sites using the same code is asked, not guessed. */
    public function testALocationCodeTwoEstablishmentsShareIsRefusedRatherThanGuessed(): void
    {
        $now = new \DateTimeImmutable();
        $second = Establishment::create($this->company, 'LYON', 'Lyon', false, $now);
        $this->em()->persist($second);
        $this->em()->flush();
        $other = $this->manageLocations()->defaultOf($second);
        $this->locations()->save(StockLocation::create($second, $other, StockLocationKind::Zone, 'Z1', 'Zone froide', $now));
        $this->em()->flush();
        $this->signedIn(['stock.read', 'stock.write']);

        $this->import(self::HEADER."\nVIS-6X40,Z1,1\n", dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame([[2, 'location_code', 'ambiguous_location', ['code' => 'Z1']]], $this->rejected());
    }

    public function testCreateModeRefusesAPairAlreadyCountedAndUpsertCountsItAgainToTheSameStock(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->import($this->twoLines());
        self::assertResponseIsSuccessful();

        $this->import($this->twoLines(), dryRun: true);
        self::assertSame([[2, 'reference', 'already_counted', []], [3, 'reference', 'already_counted', []]], $this->rejected());

        $this->import($this->twoLines(), mode: 'upsert');

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [], 'updated' => [2, 3], 'rejected' => []], $this->json());
        self::assertSame(['120.000', '7.500'], [$this->onHand('VIS-6X40', '000'), $this->onHand('FIL-2', 'Z1')], 'a count says what is there, so the same file twice leaves the same stock');
    }

    public function testSomebodyWhoCannotWriteStockIsAnsweredAsAStranger(): void
    {
        $this->signedIn(['stock.read']);

        $this->import($this->twoLines(), dryRun: true);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function twoLines(): string
    {
        return self::HEADER."\nVIS-6X40,000,120\nFIL-2,Z1,\"7,5\"\n";
    }

    private function import(string $contents, string $mode = 'create', bool $dryRun = false): void
    {
        $this->uploadFile(
            '/api/companies/'.$this->company->getId()->toRfc4122().'/imports/opening-stock',
            'stock.csv',
            $contents,
            'file',
            ['mode' => $mode, 'dryRun' => $dryRun ? '1' : '0'],
        );
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
        $count = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM stock_movement m JOIN product p ON p.id = m.product_id WHERE p.company_id = ?',
            [$this->company->getId()->toRfc4122()],
        );
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

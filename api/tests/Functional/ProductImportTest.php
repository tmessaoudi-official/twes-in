<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Audit\Domain\AuditLog;
use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldType;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Domain\ProductHomeLocation;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Products\Domain\Barcode;
use App\Module\Products\Domain\BarcodeLine;
use App\Module\Products\Domain\BarcodeRole;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductBarcode;
use App\Module\Products\Domain\ProductCategory;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\EstablishmentRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * Products imported from a file, the second subject after customers (docs/SPEC.md § 8 row 59). A product names its
 * unit by the code its company knows it under and its category by name, as a person filling in a spreadsheet would.
 */
final class ProductImportTest extends ApiTestCase
{
    private const string HEADER = 'reference,name,kind,unit_code,category,unit_price_net,cost_price,barcode,default_tax_codes,active,custom.shelf';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $now = new \DateTimeImmutable();
        $this->em()->persist(ProductCategory::create($this->company, 'Visserie', null, $now));
        $this->em()->persist(CustomFieldDefinition::create($this->company, CustomFieldEntity::Product, 'shelf', 'Rayon', CustomFieldType::Text, false, [], 1, $now));
        $this->em()->flush();
    }

    public function testAPreviewSaysWhatWouldHappenAndStoresNothing(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import($this->twoProducts(), dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => false, 'created' => [2, 3], 'updated' => [], 'rejected' => [], 'notes' => [], 'alreadyImportedAt' => null], $this->json());
        self::assertSame(0, $this->products());
    }

    public function testAnImportCreatesEveryRowUnderTheRulesOfTheProductForm(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import($this->twoProducts());

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [2, 3], 'updated' => [], 'rejected' => [], 'notes' => [], 'alreadyImportedAt' => null], $this->json());
        $screw = $this->product('VIS-6X40');
        self::assertSame('Vis 6x40 zinguée', $screw->getDetails()->name);
        self::assertSame('goods', $screw->getDetails()->kind->value);
        self::assertSame('H87', $screw->getUnit()->getCode());
        self::assertSame('Visserie', $screw->getCategory()?->getName());
        self::assertSame('0.4500', $screw->getDetails()->unitPriceNet, 'a decimal comma is read as a point');
        self::assertSame('0.2200', $screw->getDetails()->costPrice);
        self::assertSame([['unit', '6191234567897', 1]], self::codes($screw));
        self::assertSame([$this->taxId('TVA19')], $screw->getDefaultTaxComponentIds());
        self::assertSame(['shelf' => 'A12'], $screw->getCustomFields());
        self::assertFalse($screw->isActive());
        $work = $this->product('MO-TOUR');
        self::assertSame('service', $work->getDetails()->kind->value);
        self::assertSame('HUR', $work->getUnit()->getCode());
        self::assertNull($work->getCategory());
        self::assertTrue($work->isActive());
        self::assertSame(2, $this->numberOf("SELECT COUNT(*) FROM audit_log WHERE action = 'product.created'"));
    }

    /** Every flush walks every managed entity, so a written row must leave the unit of work (row 59, customers). */
    public function testWhatARowWroteIsNoLongerManagedOnceItIsWritten(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import($this->twoProducts());

        self::assertResponseIsSuccessful();
        $managed = $this->em()->getUnitOfWork()->getIdentityMap();
        self::assertSame([], $managed[Product::class] ?? [], 'no product a row wrote is still managed');
        self::assertSame([], $managed[AuditLog::class] ?? [], 'no audit row is still managed');
    }

    /** A refusal reaches the person in their language through a stable code and its parameters, whichever rule made it. */
    public function testEveryRejectedRowCarriesACodeAndItsParameters(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import(
            "reference,name,kind,unit_code,category,unit_price_net,active\n"
            .",Sans référence,goods,H87,,1.000,\n"
            ."ART-2,Matière,matière,H87,,1.000,\n"
            ."ART-3,Unité,goods,XXX,,1.000,\n"
            ."ART-4,Rayon,goods,H87,Inconnue,1.000,\n"
            ."ART-5,Prix,goods,H87,,-1,\n"
            ."ART-6,Actif,goods,H87,,1.000,peut-être\n"
            ."ART-2,Double,goods,H87,,1.000,\n",
            dryRun: true,
        );

        self::assertResponseIsSuccessful();
        $rejected = array_map(
            static fn (mixed $row): array => \is_array($row) ? [$row['line'] ?? null, $row['column'] ?? null, $row['code'] ?? null, $row['params'] ?? null] : [],
            $this->arrayAt($this->json(), 'rejected'),
        );
        self::assertSame([
            [3, 'kind', 'not_one_of', ['choices' => 'goods, service']],
            [4, 'unit_code', 'unknown_unit', ['code' => 'XXX']],
            [5, 'category', 'unknown_category', ['name' => 'Inconnue']],
            [6, 'unit_price_net', 'invalid_price', []],
            [7, 'active', 'not_yes_or_no', []],
            [8, 'reference', 'duplicate_in_file', ['line' => 3]],
        ], $rejected);
    }

    /** A row without a reference is given the next of the company's format, and told so (docs/SPEC.md § 7, 2026-09-17 (3)). */
    public function testARowWithoutAReferenceIsGivenTheNextOneAndToldWhich(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $file = "name,unit_code,unit_price_net,barcode\nCheville 8,H87,0.1,6191234567880\nCheville 10,H87,0.12,\n";

        $this->import($file, dryRun: true);
        self::assertResponseIsSuccessful();
        $given = [
            ['line' => 2, 'column' => 'reference', 'code' => 'reference_given', 'params' => ['reference' => 'ART-00001']],
            ['line' => 3, 'column' => 'reference', 'code' => 'reference_given', 'params' => ['reference' => 'ART-00002']],
        ];
        self::assertSame(['committed' => false, 'created' => [2, 3], 'updated' => [], 'rejected' => [], 'notes' => $given, 'alreadyImportedAt' => null], $this->json());
        self::assertSame(0, $this->products());

        $this->import($file);
        self::assertSame(['committed' => true, 'created' => [2, 3], 'updated' => [], 'rejected' => [], 'notes' => $given, 'alreadyImportedAt' => null], $this->json());
        self::assertSame('Cheville 8', $this->product('ART-00001')->getDetails()->name);
        self::assertSame([['unit', '6191234567880', 1]], self::codes($this->product('ART-00001')));
        self::assertSame('Cheville 10', $this->product('ART-00002')->getDetails()->name);
    }

    /** Without a reference, a row is found again by the unit code written in it, and only by that. */
    public function testARowWithoutAReferenceIsFoundAgainByItsUnitCode(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->import("name,unit_code,unit_price_net,barcode\nCheville 8,H87,0.1,6191234567880\n");
        self::assertResponseIsSuccessful();
        $again = "name,unit_price_net,barcode\nCheville 8 mm,0.15,6191234567880\n";

        $this->import($again, dryRun: true);
        self::assertSame([[2, 'barcode', 'already_exists', ['reference' => 'ART-00001']]], $this->rejections());

        $this->import($again, mode: 'upsert');
        self::assertSame(['committed' => true, 'created' => [], 'updated' => [2], 'rejected' => [], 'notes' => [], 'alreadyImportedAt' => null], $this->json());
        $this->em()->clear();
        $updated = $this->product('ART-00001');
        self::assertSame(['Cheville 8 mm', '0.1500'], [$updated->getDetails()->name, $updated->getDetails()->unitPriceNet]);
        self::assertSame(1, $this->products(), 'found again, not made twice');
    }

    /**
     * Rows found by their codes, several in one file: the code a row is found by is read for its product alone, and left
     * in the unit of work it would point at the product the row detaches, so the second row's flush would die.
     */
    public function testSeveralRowsFoundByTheirCodesAllUpdate(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->import('name,unit_code,unit_price_net,barcode
Cheville 8,H87,0.1,6191234567880
Cheville 10,H87,0.2,6191234567897
');
        self::assertResponseIsSuccessful();

        $this->import('name,barcode
Cheville 8 mm,6191234567880
Cheville 10 mm,6191234567897
', mode: 'upsert');

        self::assertResponseIsSuccessful();
        self::assertSame([[], [2, 3]], [$this->json()['created'] ?? null, $this->json()['updated'] ?? null]);
    }

    /** A row with neither a reference nor a code cannot be found again, so a name already held is pointed out. */
    public function testARowWithNeitherReferenceNorCodeWhoseNameIsHeldIsPointedOut(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);
        $this->import($this->twoProducts());
        self::assertResponseIsSuccessful();

        $this->import("name,unit_code,unit_price_net\nvis 6X40 zinguée,H87,0.5\nÉcrou M6,H87,0.2\n", mode: 'upsert', dryRun: true);

        self::assertSame(['committed' => false, 'created' => [2, 3], 'updated' => [], 'rejected' => [], 'notes' => [
            ['line' => 2, 'column' => 'name', 'code' => 'name_shared', 'params' => ['reference' => 'VIS-6X40']],
            ['line' => 2, 'column' => 'reference', 'code' => 'reference_given', 'params' => ['reference' => 'ART-00001']],
            ['line' => 3, 'column' => 'reference', 'code' => 'reference_given', 'params' => ['reference' => 'ART-00002']],
        ], 'alreadyImportedAt' => null], $this->json());
    }

    /**
     * A refusal says its own reason and names the cell at fault, rather than a reason guessed from the field it was
     * about: a code another product holds is that product's, not a row to re-run in the other mode.
     */
    public function testARefusalCarriesItsOwnReasonAndNamesTheCellAtFault(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);
        $this->import($this->twoProducts());
        self::assertResponseIsSuccessful();
        $long = str_repeat('a', 2001);

        $this->import("reference,name,unit_code,unit_price_net,barcode,custom.shelf\nART-7,Clou,H87,0.1,6191234567897,\nART-8,Clou 2,H87,0.1,,$long\n", dryRun: true);

        self::assertSame([
            [2, 'barcode', 'barcode_taken', ['reference' => 'VIS-6X40']],
            [3, 'custom.shelf', 'invalid_text', ['max' => 2000]],
        ], $this->rejections());
    }

    /** @return list<array{mixed, mixed, mixed, mixed}> */
    private function rejections(): array
    {
        return array_values(array_map(
            static fn (mixed $row): array => \is_array($row) ? [$row['line'] ?? null, $row['column'] ?? null, $row['code'] ?? null, $row['params'] ?? null] : [null, null, null, null],
            $this->arrayAt($this->json(), 'rejected'),
        ));
    }

    public function testARowWithoutAUnitIsRejectedBecauseAProductIsSoldInOne(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import("reference,name,unit_price_net\nART-9,Sans unité,1.000\n", dryRun: true);

        self::assertResponseIsSuccessful();
        $rejected = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($rejected[0] ?? null);
        self::assertSame(['unit_code', 'value_required'], [$rejected[0]['column'] ?? null, $rejected[0]['code'] ?? null]);
    }

    public function testCreateModeRefusesAKnownReferenceAndUpsertFillsInOnlyTheCellsTheRowHas(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);
        $this->import($this->twoProducts());
        self::assertResponseIsSuccessful();
        $second = "reference,name,unit_price_net\nVIS-6X40,Vis 6x40 inox,\n";

        $this->import($second, dryRun: true);
        $refused = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($refused[0] ?? null);
        self::assertSame('already_exists', $refused[0]['code'] ?? null);

        $this->import($second, mode: 'upsert');

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [], 'updated' => [2], 'rejected' => [], 'notes' => [], 'alreadyImportedAt' => null], $this->json());
        $this->em()->clear();
        $updated = $this->product('VIS-6X40');
        self::assertSame('Vis 6x40 inox', $updated->getDetails()->name);
        self::assertSame('0.4500', $updated->getDetails()->unitPriceNet, 'a blank cell keeps what is there');
        self::assertSame('Visserie', $updated->getCategory()?->getName(), 'a column the file lacks keeps what is there');
        self::assertSame(['shelf' => 'A12'], $updated->getCustomFields());
        self::assertSame([['unit', '6191234567897', 1]], self::codes($updated));
    }

    /**
     * Where a product normally lives, named by the code of a stock location (docs/SPEC.md row 101). The
     * establishment falls out of the location, so the file never carries one.
     */
    /**
     * A file writes one code, the UNIT code (docs/SPEC.md § 7, 2026-09-22 11:05): a new one replaces the unit row and
     * leaves the product's pack alone, which the file has no column for.
     */
    public function testAnUpsertedBarcodeReplacesTheUnitCodeAndKeepsThePacks(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);
        $this->import($this->twoProducts());
        self::assertResponseIsSuccessful();
        $screw = $this->product('VIS-6X40');
        $screw->replaceBarcodes([new BarcodeLine(BarcodeRole::Unit, new Barcode('6191234567897'), 1), new BarcodeLine(BarcodeRole::Pack, new Barcode('16191234567894'), 100)], new \DateTimeImmutable());
        $this->em()->flush();
        $this->em()->clear();

        $this->import("reference,name,barcode\nVIS-6X40,,036000291452\n", mode: 'upsert');

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertSame([['unit', '036000291452', 1], ['pack', '16191234567894', 100]], self::codes($this->product('VIS-6X40')));
    }

    public function testARowNamesWhereTheProductNormallyLivesByTheLocationsCode(): void
    {
        $this->aZoneCoded('Z1');
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import(self::HEADER.",home_location\nVIS-6X40,Vis 6x40 zinguée,goods,H87,,1.000,,,,,,Z1\n");

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [2], 'updated' => [], 'rejected' => [], 'notes' => [], 'alreadyImportedAt' => null], $this->json());
        self::assertSame(1, $this->numberOf(
            'SELECT COUNT(*) FROM product_home_location h JOIN product p ON p.id = h.product_id'
            .' JOIN stock_location l ON l.id = h.location_id WHERE p.reference = ? AND l.code = ?',
            ['VIS-6X40', 'Z1'],
        ));
    }

    /**
     * A file is many rows, and a row's home must leave the unit of work as its product does. A home persisted and
     * left managed still points at the product the row detached after writing it, and the NEXT row's flush walks
     * that association, finds an object it no longer knows, and takes it for a new entity — so the second row of a
     * two-row file dies where the first passed. The same class as `Establishment#company`, one association along.
     */
    public function testEveryRowOfAFileGetsItsHome(): void
    {
        $this->aZoneCoded('Z1');
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import(
            self::HEADER.",home_location\n"
            ."VIS-6X40,Vis 6x40 zinguée,goods,H87,,1.000,,,,,,Z1\n"
            ."VIS-8X60,Vis 8x60 zinguée,goods,H87,,2.000,,,,,,Z1\n"
            ."ECR-M8,Écrou M8,goods,H87,,0.300,,,,,,Z1\n",
        );

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [2, 3, 4], 'updated' => [], 'rejected' => [], 'notes' => [], 'alreadyImportedAt' => null], $this->json());
        self::assertSame(3, $this->numberOf(
            'SELECT COUNT(*) FROM product_home_location h JOIN stock_location l ON l.id = h.location_id'
            .' WHERE l.code = ?',
            ['Z1'],
        ));
        $managed = $this->em()->getUnitOfWork()->getIdentityMap();
        self::assertSame([], $managed[ProductHomeLocation::class] ?? [], 'no home a row wrote is still managed');
    }

    public function testARowSetsTheReorderPointOfTheEstablishmentItsHomeIsIn(): void
    {
        // docs/SPEC.md § 7, 2026-09-24 11:40: a reorder point per product per establishment, and an import column.
        $this->aZoneCoded('Z1');
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import(self::HEADER.",reorder_point\nVIS-6X40,Vis 6x40 zinguée,goods,H87,,1.000,,,,,,12\n");

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [2], 'updated' => [], 'rejected' => [], 'notes' => [], 'alreadyImportedAt' => null], $this->json());
        self::assertSame(['12.000'], $this->pointsOf('VIS-6X40'), 'the company has one establishment, so the row needs no home to say which');

        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $this->em()->persist(Establishment::create($company, 'SFAX', 'Sfax', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->import("reference,name,reorder_point\nVIS-6X40,Vis 6x40 zinguée,5\n", mode: 'upsert');
        self::assertResponseStatusCodeSame(422);
        $rejected = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($rejected[0] ?? null);
        self::assertSame(['reorder_point', 'ambiguous_establishment'], [$rejected[0]['column'] ?? null, $rejected[0]['code'] ?? null], 'two establishments and no home: the file cannot say which');

        $this->import("reference,name,home_location,reorder_point\nVIS-6X40,Vis 6x40 zinguée,Z1,5\n", mode: 'upsert');
        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [], 'updated' => [2], 'rejected' => [], 'notes' => [], 'alreadyImportedAt' => null], $this->json());
        self::assertSame(['5.000'], $this->pointsOf('VIS-6X40'), 'the home names the establishment');

        $this->import("reference,name,home_location,reorder_point\nVIS-6X40,Vis 6x40 zinguée,Z1,-1\n", mode: 'upsert');
        self::assertResponseStatusCodeSame(422);
        $rejected = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($rejected[0] ?? null);
        self::assertSame(['reorder_point', 'invalid_value'], [$rejected[0]['column'] ?? null, $rejected[0]['code'] ?? null]);

        $this->import("reference,name,home_location,reorder_point\nVIS-6X40,Vis 6x40 zinguée,Z1,\n", mode: 'upsert');
        self::assertResponseIsSuccessful();
        self::assertSame(['5.000'], $this->pointsOf('VIS-6X40'), 'a blank cell keeps what is there');
    }

    /** @return list<string> the product's reorder points' quantities */
    private function pointsOf(string $reference): array
    {
        $quantities = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT r.quantity FROM product_reorder_point r JOIN product p ON p.id = r.product_id WHERE p.reference = ?',
            [$reference],
        );

        return array_map(static fn (mixed $quantity): string => \is_string($quantity) ? $quantity : '', $quantities);
    }

    /** A blank cell keeps what is there, like every other cell: a file that does not mention homes does not clear them. */
    public function testAnUpsertLeavingTheHomeBlankKeepsIt(): void
    {
        $this->aZoneCoded('Z1');
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);
        $this->import(self::HEADER.",home_location\nVIS-6X40,Vis 6x40 zinguée,goods,H87,,1.000,,,,,,Z1\n");
        self::assertResponseIsSuccessful();

        $this->import("reference,name,home_location\nVIS-6X40,Vis 6x40 inox,\n", mode: 'upsert');

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [], 'updated' => [2], 'rejected' => [], 'notes' => [], 'alreadyImportedAt' => null], $this->json());
        self::assertSame(1, $this->numberOf(
            'SELECT COUNT(*) FROM product_home_location h JOIN stock_location l ON l.id = h.location_id'
            .' JOIN product p ON p.id = h.product_id WHERE p.reference = ? AND l.code = ?',
            ['VIS-6X40', 'Z1'],
        ));
    }

    /** A code no location has, and a code two establishments share: named, never guessed at. */
    public function testAHomeThatCannotBeResolvedRejectsTheRowNamingTheCell(): void
    {
        $now = new \DateTimeImmutable();
        $this->aZoneCoded('Z1');
        $second = Establishment::create($this->company, 'LYON', 'Lyon', false, $now);
        $this->em()->persist($second);
        $this->em()->flush();
        $this->locations()->save(StockLocation::create($second, $this->manageLocations()->defaultOf($second), StockLocationKind::Zone, 'Z1', 'Zone froide', $now));
        $this->em()->flush();
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import(
            "reference,name,unit_code,unit_price_net,home_location\n"
            ."ART-1,Inconnu,H87,1.000,ZZZ\n"
            ."ART-2,Partagé,H87,1.000,Z1\n",
            dryRun: true,
        );

        self::assertResponseIsSuccessful();
        $rejected = array_map(
            static fn (mixed $row): array => \is_array($row) ? [$row['line'] ?? null, $row['column'] ?? null, $row['code'] ?? null, $row['params'] ?? null] : [],
            $this->arrayAt($this->json(), 'rejected'),
        );
        self::assertSame([
            [2, 'home_location', 'unknown_location', ['code' => 'ZZZ']],
            [3, 'home_location', 'ambiguous_location', ['code' => 'Z1']],
        ], $rejected);

        // Not a dry run this time: a rejected row leaves NO product behind. The home is resolved before anything is
        // written, and a file with any rejection is rolled back whole — so neither half can land on its own.
        $this->import(
            "reference,name,unit_code,unit_price_net,home_location\nART-1,Inconnu,H87,1.000,ZZZ\n",
        );

        // A real import that rejects a row answers 422, where a preview of the same file answers 200.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertFalse($this->json()['committed'] ?? null);
        self::assertSame(0, $this->products());
    }

    /**
     * A company that keeps no stock has nowhere to put a product, so its file is not asked for a shelf — and one
     * naming the column is refused for it, rather than having the cell quietly dropped.
     */
    public function testACompanyWithoutStockIsNeverAskedWhereAProductLives(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read', 'company.read', 'company.settings']);
        $this->sendJson('PUT', $this->companyPath().'/modules/inventory', ['enabled' => false]);
        self::assertResponseIsSuccessful();

        $this->getJson($this->companyPath().'/imports/products');

        self::assertResponseIsSuccessful();
        $columns = $this->arrayAt($this->json(), 'columns');
        $keys = array_map(static fn (mixed $column): mixed => \is_array($column) ? ($column['key'] ?? null) : null, $columns);
        self::assertNotContains('home_location', $keys);

        $this->import(self::HEADER.",home_location\nVIS-6X40,Vis,goods,H87,,1.000,,,,,,Z1\n", dryRun: true);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('home_location', (string) $this->client->getResponse()->getContent());
    }

    private function aZoneCoded(string $code): void
    {
        $now = new \DateTimeImmutable();
        $establishment = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0];
        $this->locations()->save(StockLocation::create($establishment, $this->manageLocations()->defaultOf($establishment), StockLocationKind::Zone, $code, 'Zone froide', $now));
        $this->em()->flush();
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

    public function testSomebodyWhoCannotWriteProductsIsAnsweredAsAStranger(): void
    {
        $this->signedIn(['product.read']);

        $this->import($this->twoProducts(), dryRun: true);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function twoProducts(): string
    {
        return self::HEADER
            ."\nVIS-6X40,Vis 6x40 zinguée,goods,H87,Visserie,\"0,45\",\"0,22\",6191234567897,TVA19,non,A12"
            ."\nMO-TOUR,Tournage à l'heure,service,HUR,,45.000,,,,,\n";
    }

    private function import(string $contents, string $mode = 'create', bool $dryRun = false, string $name = 'products.csv'): void
    {
        $this->uploadFile($this->companyPath().'/imports/products', $name, $contents, 'file', ['mode' => $mode, 'dryRun' => $dryRun ? '1' : '0']);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('shop@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('shop@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function products(): int
    {
        return $this->numberOf('SELECT COUNT(*) FROM product WHERE company_id = ?', [$this->company->getId()->toRfc4122()]);
    }

    /** @param list<string> $parameters */
    private function numberOf(string $sql, array $parameters = []): int
    {
        $count = $this->em()->getConnection()->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function product(string $reference): Product
    {
        $product = $this->em()->getRepository(Product::class)->findOneBy(['reference' => $reference]);
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    private function taxId(string $code): string
    {
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax->getId()->toRfc4122();
    }

    /** @return list<array{string, string, int}> */
    private static function codes(Product $product): array
    {
        return array_map(static fn (ProductBarcode $row): array => [$row->getRole()->value, $row->getCode(), $row->getQuantity()], $product->getBarcodes());
    }
}

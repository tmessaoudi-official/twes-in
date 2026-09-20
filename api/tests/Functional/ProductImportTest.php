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
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductCategory;
use App\Tenancy\Domain\Company;
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
        $this->signedIn(['product.read', 'product.write']);

        $this->import($this->twoProducts(), dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => false, 'created' => [2, 3], 'updated' => [], 'rejected' => []], $this->json());
        self::assertSame(0, $this->products());
    }

    public function testAnImportCreatesEveryRowUnderTheRulesOfTheProductForm(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->import($this->twoProducts());

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [2, 3], 'updated' => [], 'rejected' => []], $this->json());
        $screw = $this->product('VIS-6X40');
        self::assertSame('Vis 6x40 zinguée', $screw->getDetails()->name);
        self::assertSame('goods', $screw->getDetails()->kind->value);
        self::assertSame('H87', $screw->getUnit()->getCode());
        self::assertSame('Visserie', $screw->getCategory()?->getName());
        self::assertSame('0.4500', $screw->getDetails()->unitPriceNet, 'a decimal comma is read as a point');
        self::assertSame('0.2200', $screw->getDetails()->costPrice);
        self::assertSame('6191234567890', $screw->getDetails()->barcode);
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
        $this->signedIn(['product.read', 'product.write']);

        $this->import($this->twoProducts());

        self::assertResponseIsSuccessful();
        $managed = $this->em()->getUnitOfWork()->getIdentityMap();
        self::assertSame([], $managed[Product::class] ?? [], 'no product a row wrote is still managed');
        self::assertSame([], $managed[AuditLog::class] ?? [], 'no audit row is still managed');
    }

    /** A refusal reaches the person in their language through a stable code and its parameters, whichever rule made it. */
    public function testEveryRejectedRowCarriesACodeAndItsParameters(): void
    {
        $this->signedIn(['product.read', 'product.write']);

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
            [2, 'reference', 'value_required', []],
            [3, 'kind', 'not_one_of', ['choices' => 'goods, service']],
            [4, 'unit_code', 'unknown_unit', ['code' => 'XXX']],
            [5, 'category', 'unknown_category', ['name' => 'Inconnue']],
            [6, 'unit_price_net', 'invalid_price', []],
            [7, 'active', 'not_yes_or_no', []],
            [8, 'reference', 'duplicate_in_file', ['line' => 3]],
        ], $rejected);
    }

    public function testARowWithoutAUnitIsRejectedBecauseAProductIsSoldInOne(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->import("reference,name,unit_price_net\nART-9,Sans unité,1.000\n", dryRun: true);

        self::assertResponseIsSuccessful();
        $rejected = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($rejected[0] ?? null);
        self::assertSame(['unit_code', 'value_required'], [$rejected[0]['column'] ?? null, $rejected[0]['code'] ?? null]);
    }

    public function testCreateModeRefusesAKnownReferenceAndUpsertFillsInOnlyTheCellsTheRowHas(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->import($this->twoProducts());
        self::assertResponseIsSuccessful();
        $second = "reference,name,unit_price_net\nVIS-6X40,Vis 6x40 inox,\n";

        $this->import($second, dryRun: true);
        $refused = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($refused[0] ?? null);
        self::assertSame('already_exists', $refused[0]['code'] ?? null);

        $this->import($second, mode: 'upsert');

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [], 'updated' => [2], 'rejected' => []], $this->json());
        $this->em()->clear();
        $updated = $this->product('VIS-6X40');
        self::assertSame('Vis 6x40 inox', $updated->getDetails()->name);
        self::assertSame('0.4500', $updated->getDetails()->unitPriceNet, 'a blank cell keeps what is there');
        self::assertSame('Visserie', $updated->getCategory()?->getName(), 'a column the file lacks keeps what is there');
        self::assertSame(['shelf' => 'A12'], $updated->getCustomFields());
        self::assertSame('6191234567890', $updated->getDetails()->barcode);
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
            ."\nVIS-6X40,Vis 6x40 zinguée,goods,H87,Visserie,\"0,45\",\"0,22\",6191234567890,TVA19,non,A12"
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
}

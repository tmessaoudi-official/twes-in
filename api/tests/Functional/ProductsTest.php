<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Products\Domain\Barcode;
use App\Module\Products\Domain\BarcodeLine;
use App\Module\Products\Domain\BarcodeRole;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductCategory;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

final class ProductsTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testTheOptionsSayWhatTheProductFormAsksFor(): void
    {
        $this->signedIn(['product.read']);

        $this->getJson($this->companyPath().'/product-options');

        self::assertResponseIsSuccessful();
        $options = $this->json();
        self::assertSame(['TND', 3], [$options['currency'], $options['currencyScale']]);
        $units = $this->arrayAt($options, 'units');
        self::assertContains('C62', array_column($units, 'code'));
        $first = $units[0];
        self::assertIsArray($first);
        self::assertSame(['id', 'code', 'name', 'decimals'], array_keys($first));
        $codes = array_column($this->arrayAt($options, 'taxes'), 'code');
        self::assertContains('TVA19', $codes);
        self::assertContains('FODEC', $codes);
        self::assertNotContains('TIMBRE', $codes, 'a stamp is charged on the document');
        self::assertNotContains('RS1', $codes, 'a withholding is charged on the document');
    }

    public function testAWriterAddsAProductWithItsCategoryAndLineTaxes(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->postJson($this->companyPath().'/product-categories', ['name' => 'Matériel']);
        $categoryId = $this->stringAt($this->json(), 'id');

        $this->postJson($this->path(), $this->product(['categoryId' => $categoryId, 'defaultTaxComponentIds' => [$this->taxId('FODEC'), $this->taxId('TVA19')], 'unitPriceNet' => '1250.5']));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json();
        self::assertSame('ART-001', $created['reference']);
        self::assertSame('goods', $created['kind']);
        self::assertSame('1250.5000', $created['unitPriceNet']);
        self::assertNull($created['costPrice']);
        self::assertSame($this->unitId('C62'), $created['unitId']);
        self::assertSame($categoryId, $created['categoryId']);
        self::assertSame([$this->taxId('FODEC'), $this->taxId('TVA19')], $created['defaultTaxComponentIds']);
        self::assertSame([], $created['barcodes']);
        self::assertTrue($created['isActive']);

        $this->getJson($this->path($this->stringAt($created, 'id')));
        self::assertResponseIsSuccessful();
        self::assertSame('1250.5000', $this->json()['unitPriceNet'], 'the database keeps four decimals too');
        $this->getJson($this->path());
        self::assertCount(1, $this->jsonList());
        self::assertSame('[]', $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'product.created'"));
        $this->getJson($this->companyPath().'/product-categories');
        self::assertSame([1], array_column($this->jsonList(), 'productCount'));
    }

    public function testWhatTheCompanyOrTheShapeRefusesAnswersUnprocessableNamingTheField(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);
        $absent = '0192c3a4-0000-7000-8000-000000000000';

        foreach ([
            'reference' => ['reference' => 'ART 001'],
            'name' => ['name' => ''],
            'kind' => ['kind' => 'robot'],
            'unitPriceNet' => ['unitPriceNet' => '12,5'],
            'costPrice' => ['costPrice' => '-3'],
            'unitId' => ['unitId' => $absent],
            'categoryId' => ['categoryId' => $absent],
            'defaultTaxComponentIds' => ['defaultTaxComponentIds' => [$this->taxId('TIMBRE')]],
        ] as $field => $change) {
            $this->postJson($this->path(), $this->product($change));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
        }

        $this->postJson($this->path(), $this->product(['defaultTaxComponentIds' => ['first' => $this->taxId('TVA19')]]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the default taxes are a list, never a map');
        $this->getJson($this->path());
        self::assertSame([], $this->jsonList());
    }

    public function testAReferenceAnotherProductHasAnswersConflict(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->postJson($this->path(), $this->product());

        $this->postJson($this->path(), $this->product(['name' => 'Autre']));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    /**
     * A product's codes are written as one list, on their own (docs/SPEC.md § 7, 2026-09-22 11:05): the product's
     * PUT never touches them, so the product form cannot wipe codes it does not show.
     */
    public function testAProductsCodesAreWrittenAsOneListAndThePlainRevisionKeepsThem(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->postJson($this->path(), $this->product());
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id).'/barcodes', ['barcodes' => [['role' => 'pack', 'code' => '13017620422000', 'quantity' => 12], ['role' => 'unit', 'code' => '3017620422003', 'quantity' => 1]]]);

        self::assertResponseIsSuccessful();
        $codes = [['role' => 'unit', 'code' => '3017620422003', 'quantity' => 1, 'supplierId' => null], ['role' => 'pack', 'code' => '13017620422000', 'quantity' => 12, 'supplierId' => null]];
        self::assertSame($codes, $this->json()['barcodes']);
        self::assertSame('{"fields": ["barcodes"]}', $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'product.revised'"));

        $this->sendJson('PUT', $this->path($id), $this->product(['name' => 'Renommé', 'barcodes' => []]));
        self::assertResponseIsSuccessful();
        self::assertSame($codes, $this->json()['barcodes'], 'a revision of the product keeps its codes, whatever it sends');
        $this->getJson($this->path($id));
        self::assertSame($codes, $this->json()['barcodes']);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function refusedCodes(): iterable
    {
        yield 'a space inside' => ['barcodes.0.code', ['role' => 'unit', 'code' => 'with space', 'quantity' => 1]];
        yield 'a wrong check digit' => ['barcodes.0.code', ['role' => 'unit', 'code' => '3017620422004', 'quantity' => 1]];
        yield 'a pack of one' => ['barcodes.0.quantity', ['role' => 'pack', 'code' => 'P-1', 'quantity' => 1]];
        yield 'a supplier code without its supplier' => ['barcodes.0.supplierId', ['role' => 'supplier', 'code' => 'P-1', 'quantity' => 1]];
        yield 'an unknown supplier' => ['barcodes.0.supplierId', ['role' => 'supplier', 'code' => 'P-1', 'quantity' => 1, 'supplierId' => '0192c3a4-0000-7000-8000-000000000000']];
        yield 'an unknown role' => ['barcodes[0].role', ['role' => 'pallet', 'code' => 'P-1', 'quantity' => 1]];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('refusedCodes')]
    public function testAMalformedCodeIsRefusedNamingItsRow(string $field, array $row): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);
        $this->postJson($this->path(), $this->product());
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id).'/barcodes', ['barcodes' => [$row]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
    }

    /**
     * A code is unique within the company, every role together, so a scan finds exactly one product (docs/SPEC.md § 7,
     * 2026-09-17, 2026-09-22 11:05). The refusal names the row and the product holding the code, for the form to say.
     */
    public function testACodeAnotherProductHoldsAnswersConflictNamingItWhileKeepingItsOwnDoesNot(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $first = $this->created($this->product());
        $second = $this->created($this->product(['reference' => 'ART-002']));
        $this->sendJson('PUT', $this->path($first).'/barcodes', ['barcodes' => [['role' => 'unit', 'code' => '3017620422003', 'quantity' => 1]]]);
        self::assertResponseIsSuccessful();

        // The same GTIN with a leading zero, on a pack: still the same code.
        $this->sendJson('PUT', $this->path($second).'/barcodes', ['barcodes' => [['role' => 'internal', 'code' => 'X-1', 'quantity' => 1], ['role' => 'pack', 'code' => '03017620422003', 'quantity' => 6]]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a second product cannot take the same code');
        self::assertStringContainsString('barcodes.1.code: 03017620422003 is already a code of ART-001', $this->stringAt($this->json(), 'detail'));

        // Its own code is not "taken" by anyone else, and the same list again is no change: nothing more is audited.
        $this->sendJson('PUT', $this->path($first).'/barcodes', ['barcodes' => [['role' => 'unit', 'code' => '3017620422003', 'quantity' => 1]]]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->em()->getConnection()->fetchFirstColumn("SELECT id FROM audit_log WHERE action = 'product.revised'"));
    }

    /** A code spelled whole finds its product in the list and in a document's picker, first, whatever else matches. */
    public function testACodeSpelledWholeFindsItsProductFirst(): void
    {
        $this->signedIn(['product.read', 'product.write', 'invoice.read', 'invoice.write']);
        $screws = $this->created($this->product(['reference' => 'ZZZ-9', 'name' => 'Carton de vis']));
        $this->sendJson('PUT', $this->path($screws).'/barcodes', ['barcodes' => [['role' => 'pack', 'code' => '10012345678902', 'quantity' => 100]]]);
        // Its name matches the words "carton" too; the product whose code the words ARE comes first all the same.
        $this->created($this->product(['reference' => 'AAA-1', 'name' => 'Carton vide']));

        $this->getJson($this->path().'?q=10012345678902');
        self::assertSame(['ZZZ-9'], array_column($this->jsonList(), 'reference'));
        $this->getJson($this->companyPath().'/invoice-options/products?q=10012345678902');
        self::assertSame(['ZZZ-9'], array_column($this->jsonList(), 'reference'));
        $this->getJson($this->companyPath().'/invoice-options/products?q=carton');
        self::assertSame(['AAA-1', 'ZZZ-9'], array_column($this->jsonList(), 'reference'), 'words alone keep the reference order');
        // Part of a code finds nothing: a code is found whole.
        $this->getJson($this->path().'?q=1001234567');
        self::assertSame([], $this->jsonList());
        // A GS1 scan finds the product its (01) names, the lot and serial it carried being no part of the product.
        $this->getJson($this->path().'?q='.rawurlencode(']C10110012345678902'."10LOT-7\x1D".'21SN99'));
        self::assertSame(['ZZZ-9'], array_column($this->jsonList(), 'reference'));
        $this->getJson($this->companyPath().'/invoice-options/products?q='.rawurlencode('(01)10012345678902(10)B2'));
        self::assertSame(['ZZZ-9'], array_column($this->jsonList(), 'reference'));
    }

    /** @param array<string, mixed> $body */
    private function created(array $body): string
    {
        $this->postJson($this->path(), $body);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    public function testAPageOfTheListCostsTheSameStatementsWhateverTheRowsItHolds(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        // Each its own unit and codes, so the identity map cannot hide a read per row.
        foreach (['C62', 'H87', 'HUR', 'DAY', 'KGM', 'MTR'] as $n => $unit) {
            $id = $this->created($this->product(['reference' => 'ART-10'.$n, 'unitId' => $this->unitId($unit)]));
            $this->sendJson('PUT', $this->path($id).'/barcodes', ['barcodes' => [['role' => 'unit', 'code' => 'CODE-'.$n, 'quantity' => 1]]]);
            self::assertResponseIsSuccessful();
        }
        $this->em()->clear();

        $statements = [1 => $this->statementsForAPageOf($this->path(), 1), 6 => $this->statementsForAPageOf($this->path(), 6)];

        self::assertSame($statements[1], $statements[6], 'six rows cost what one does (audit PF-07)');
        // Measured 12 on 2026-09-25 (17 for six rows before), the session and the company's checks included.
        self::assertLessThanOrEqual(12, $statements[6]);
    }

    public function testARevisionIsAuditedWithTheNamesOfTheFieldsItChanged(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->postJson($this->path(), $this->product());
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), $this->product(['unitPriceNet' => '1300', 'unitId' => $this->unitId('HUR'), 'kind' => 'service', 'isActive' => false]));

        self::assertResponseIsSuccessful();
        self::assertSame(['1300.0000', 'service', false], [$this->json()['unitPriceNet'], $this->json()['kind'], $this->json()['isActive']]);
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'product.revised'");
        self::assertIsString($changes);
        self::assertSame(['fields' => ['kind', 'unitPriceNet', 'unitId', 'isActive']], json_decode($changes, true));
    }

    public function testAProductsCustomFieldsAreTheCompanysFieldsForProducts(): void
    {
        $this->signedIn(['product.read', 'product.write', 'company.read', 'company.settings']);
        $this->postJson($this->companyPath().'/custom-fields', ['entity' => 'product', 'key' => 'warranty', 'label' => 'Garantie (mois)', 'type' => 'number', 'required' => true, 'choices' => [], 'sortOrder' => 0, 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postJson($this->companyPath().'/custom-fields', ['entity' => 'customer', 'key' => 'sector', 'label' => 'Secteur', 'type' => 'text', 'required' => false, 'choices' => [], 'sortOrder' => 0, 'isActive' => true]);

        $this->getJson($this->companyPath().'/custom-fields?entity=product');
        self::assertSame(['warranty'], array_column($this->jsonList(), 'key'));

        $this->postJson($this->path(), $this->product());
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('customFields.warranty', (string) $this->client->getResponse()->getContent());
        $this->postJson($this->path(), $this->product(['customFields' => ['warranty' => 24, 'sector' => 'retail']]));
        self::assertStringContainsString('customFields.sector', (string) $this->client->getResponse()->getContent());

        $this->postJson($this->path(), $this->product(['customFields' => ['warranty' => 24]]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['warranty' => 24], $this->json()['customFields']);
    }

    public function testAReaderOnlyReadsAndAnotherCompanysProductIsNotFound(): void
    {
        $globex = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($globex);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $globex->getId());
        self::assertNotNull($unit);
        $theirs = Product::create($globex, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '10'), $unit, null, [], new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->flush();
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['product.read'], 'reader');
        $this->signedIn(['product.read', 'product.write']);
        $this->postJson($this->path(), $this->product());
        $mine = $this->stringAt($this->json(), 'id');

        $this->sendJson('POST', '/api/auth/logout');
        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->path($mine));
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->path($mine), $this->product());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path(), $this->product(['reference' => 'ART-002']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->getJson($this->path($theirs->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/companies/'.$globex->getId()->toRfc4122().'/products');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path('not-a-uuid'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheListIsAPageSearchedNarrowedAndSortedByTheApi(): void
    {
        $now = new \DateTimeImmutable();
        $units = static::getContainer()->get(UnitRepository::class);
        $piece = $units->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        foreach (range(1, 27) as $n) {
            $this->em()->persist(Product::create($this->company, \sprintf('ART-%04d', $n), new ProductDetails('Article '.$n, null, ProductKind::Goods, '10'), $piece, null, [], $now));
        }
        $grocery = ProductCategory::create($this->company, 'Épicerie', null, $now);
        $this->em()->persist($grocery);
        $coffee = Product::create($this->company, 'ART-0100', new ProductDetails('Café moulu Carthage', null, ProductKind::Service, '12.5'), $piece, $grocery, [], $now);
        $coffee->replaceBarcodes([new BarcodeLine(BarcodeRole::Unit, new Barcode('6191234567897'), 1)], $now);
        $this->em()->persist($coffee);
        $retired = Product::create($this->company, 'ART-0101', new ProductDetails('Zitouna thé vert', null, ProductKind::Goods, '4'), $piece, null, [], $now);
        $retired->revise('ART-0101', new ProductDetails('Zitouna thé vert', null, ProductKind::Goods, '4'), $piece, null, [], false, $now);
        $this->em()->persist($retired);
        $this->em()->flush();
        $this->signedIn(['product.read']);

        $this->getJson($this->path());
        self::assertCount(25, $this->jsonList());
        self::assertSame(29, $this->jsonPage()['totalItems']);

        foreach ([
            'q=cafe' => ['ART-0100'],
            'q=CARTHAGE' => ['ART-0100'],
            // A code is found whole, never by a part of it (docs/SPEC.md § 7, 2026-09-22).
            'q=6191234567897' => ['ART-0100'],
            'q=6191234' => [],
            'q=art-0101' => ['ART-0101'],
            'q=zz' => [],
            'kind=service' => ['ART-0100'],
            'isActive=false' => ['ART-0101'],
            'order[reference]=desc&itemsPerPage=1' => ['ART-0101'],
            'order[name]=desc&itemsPerPage=1' => ['ART-0101'],
            'order[kind]=desc&itemsPerPage=1' => ['ART-0100'],
            'order[category]=asc&itemsPerPage=1' => ['ART-0100'],
            'order[category]=desc&itemsPerPage=1' => ['ART-0100'],
            'order[isActive]=asc&itemsPerPage=1' => ['ART-0101'],
        ] as $query => $references) {
            $this->getJson($this->path().'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($references, array_column($this->jsonList(), 'reference'), $query);
        }

        $this->getJson($this->path().'?kind=robot');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function product(array $changes = []): array
    {
        return [...[
            'reference' => 'ART-001',
            'name' => 'Portable 14"',
            'description' => null,
            'kind' => 'goods',
            'unitId' => $this->unitId('C62'),
            'unitPriceNet' => '1250',
            'costPrice' => null,
            'categoryId' => null,
            'barcodes' => [],
            'defaultTaxComponentIds' => [],
            'customFields' => [],
            'isActive' => true,
        ], ...$changes];
    }

    private function unitId(string $code): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($unit);

        return $unit->getId()->toRfc4122();
    }

    private function taxId(string $code): string
    {
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax->getId()->toRfc4122();
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function path(?string $productId = null): string
    {
        return $this->companyPath().'/products'.(null === $productId ? '' : '/'.$productId);
    }
}

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
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
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

        $this->postJson($this->path(), $this->product(['categoryId' => $categoryId, 'defaultTaxComponentIds' => [$this->taxId('FODEC'), $this->taxId('TVA19')], 'unitPriceNet' => '1250.5', 'barcode' => '3017620422003']));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json();
        self::assertSame('ART-001', $created['reference']);
        self::assertSame('goods', $created['kind']);
        self::assertSame('1250.5000', $created['unitPriceNet']);
        self::assertNull($created['costPrice']);
        self::assertSame($this->unitId('C62'), $created['unitId']);
        self::assertSame($categoryId, $created['categoryId']);
        self::assertSame([$this->taxId('FODEC'), $this->taxId('TVA19')], $created['defaultTaxComponentIds']);
        self::assertSame('3017620422003', $created['barcode']);
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
        $this->signedIn(['product.read', 'product.write']);
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
            'barcode' => ['barcode' => 'with space'],
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
            'barcode' => null,
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

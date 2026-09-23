<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Products\Domain\Barcode;
use App\Module\Products\Domain\BarcodeLine;
use App\Module\Products\Domain\BarcodeRole;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a product costs the company, and the codes its suppliers print, are read with product.cost.read alone
 * (docs/SPEC.md § 7, 2026-09-23 09:45, slice 5): without it the API sends neither, and a write by someone who cannot
 * see them keeps what is stored rather than erasing what it was never shown.
 */
final class ProductCostTest extends ApiTestCase
{
    private const string UNIT_CODE = '6191234567897';
    private const string SUPPLIER_CODE = 'F-VIS-6X40';

    private Company $company;
    private Vendor $vendor;
    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $now = new \DateTimeImmutable();
        $this->vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Sotumag'), $now);
        $this->em()->persist($this->vendor);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $product = Product::create($this->company, 'VIS-6X40', new ProductDetails('Vis 6x40', null, ProductKind::Goods, '0.45', '0.22'), $unit, null, [], $now);
        $product->replaceBarcodes([
            new BarcodeLine(BarcodeRole::Unit, new Barcode(self::UNIT_CODE), 1),
            new BarcodeLine(BarcodeRole::Supplier, new Barcode(self::SUPPLIER_CODE), 1, $this->vendor),
        ], $now);
        $this->em()->persist($product);
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();
    }

    public function testWithThePermissionTheCostAndTheSuppliersCodesAreRead(): void
    {
        $this->signedIn(['product.read', 'product.cost.read']);

        $this->getJson($this->path());

        self::assertResponseIsSuccessful();
        self::assertSame('0.2200', $this->json()['costPrice']);
        self::assertSame([self::UNIT_CODE, self::SUPPLIER_CODE], array_column($this->listAt($this->json(), 'barcodes'), 'code'));
    }

    public function testWithoutItNeitherTheItemNorTheListSendsTheCostOrASuppliersCode(): void
    {
        $this->signedIn(['product.read']);

        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['costPrice']);
        self::assertSame([self::UNIT_CODE], array_column($this->listAt($this->json(), 'barcodes'), 'code'));

        $this->getJson($this->companyPath().'/products');
        self::assertResponseIsSuccessful();
        $raw = $this->client->getResponse()->getContent();
        self::assertIsString($raw);
        self::assertStringNotContainsString('0.2200', $raw);
        self::assertStringNotContainsString(self::SUPPLIER_CODE, $raw);
    }

    public function testARevisionByAWriterWhoCannotSeeTheCostKeepsIt(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->sendJson('PUT', $this->path(), $this->body(['name' => 'Vis 6x40 zinguée', 'costPrice' => '5']));

        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['costPrice']);
        self::assertSame(['0.2200', 'Vis 6x40 zinguée'], [$this->stored()->getDetails()->costPrice, $this->stored()->getDetails()->name]);
        self::assertSame([self::UNIT_CODE, self::SUPPLIER_CODE], $this->storedCodes(), 'nor does it lose a supplier\'s code');
    }

    public function testAWriterWhoCannotSeeTheCostCreatesAProductWithoutOne(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->postJson($this->companyPath().'/products', $this->body(['reference' => 'VIS-8X60', 'costPrice' => '5']));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($this->json()['costPrice']);
        $this->getJson($this->companyPath().'/products/'.$this->stringAt($this->json(), 'id'));
        self::assertNull($this->json()['costPrice']);
    }

    public function testAWriterWhoCannotSeeTheSuppliersCodesKeepsThemWhenWritingTheOthers(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->sendJson('PUT', $this->path().'/barcodes', ['barcodes' => [['role' => 'unit', 'code' => '3017620422003', 'quantity' => 1]]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['3017620422003'], array_column($this->listAt($this->json(), 'barcodes'), 'code'));
        self::assertSame(['3017620422003', self::SUPPLIER_CODE], $this->storedCodes());
    }

    public function testAWriterWhoCannotSeeTheSuppliersCodesCannotWriteOne(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->sendJson('PUT', $this->path().'/barcodes', ['barcodes' => [
            ['role' => 'unit', 'code' => self::UNIT_CODE, 'quantity' => 1],
            ['role' => 'supplier', 'code' => 'F-OTHER', 'quantity' => 1, 'supplierId' => $this->vendor->getId()->toRfc4122()],
        ]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringStartsWith('barcodes.1.role:', $this->stringAt($this->json(), 'detail'));
        self::assertSame([self::UNIT_CODE, self::SUPPLIER_CODE], $this->storedCodes());
    }

    public function testTheImportGuideOffersTheCostColumnOnlyToThoseWhoMayReadIt(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->getJson($this->companyPath().'/imports/products');
        self::assertResponseIsSuccessful();
        self::assertNotContains('cost_price', array_column($this->listAt($this->json(), 'columns'), 'key'));

        $this->uploadFile($this->companyPath().'/imports/products', 'products.csv', "reference,name,kind,unit_code,unit_price_net,cost_price\nVIS-8X60,Vis 8x60,goods,C62,0.60,0.30\n", 'file', ['mode' => 'create', 'dryRun' => '0']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('cost_price', (string) $this->client->getResponse()->getContent());
    }

    public function testTheImportGuideOffersTheCostColumnWithThePermission(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->getJson($this->companyPath().'/imports/products');

        self::assertResponseIsSuccessful();
        self::assertContains('cost_price', array_column($this->listAt($this->json(), 'columns'), 'key'));
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function body(array $changes): array
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);

        return [...[
            'reference' => 'VIS-6X40',
            'name' => 'Vis 6x40',
            'description' => null,
            'kind' => 'goods',
            'unitId' => $unit->getId()->toRfc4122(),
            'unitPriceNet' => '0.45',
            'costPrice' => null,
            'categoryId' => null,
            'defaultTaxComponentIds' => [],
            'customFields' => [],
            'isActive' => true,
        ], ...$changes];
    }

    private function stored(): Product
    {
        $this->em()->clear();
        $product = $this->em()->find(Product::class, $this->productId);
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    /** @return list<string> */
    private function storedCodes(): array
    {
        return array_map(static fn ($code): string => $code->getCode(), $this->stored()->getBarcodes());
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<mixed>
     */
    private function listAt(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        self::assertIsArray($value);

        return array_values($value);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('shop@twes.local', 'password-1234', $this->company, $permissions, 'clerk');
        $this->login('shop@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function path(): string
    {
        return $this->companyPath().'/products/'.$this->productId;
    }
}

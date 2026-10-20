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
use App\Module\Products\Domain\Model\ProductBarcode;
use App\Module\Products\Domain\ValueObject\Barcode;
use App\Module\Products\Domain\ValueObject\BarcodeLine;
use App\Module\Products\Domain\ValueObject\BarcodeRole;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\ModuleRegistry\Domain\Model\ModuleState;
use App\Tenancy\Domain\Model\Company;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Who each product is bought from, from a file: one row per product and vendor, with the vendor's own code for it when
 * there is one. A file adds links and codes and removes none, as the product file's supplier columns do.
 */
final class ProductVendorImportTest extends ApiTestCase
{
    private const string HEADER = "product_reference,vendor_number,vendor_code\n";

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('H87', $this->company->getId());
        self::assertNotNull($unit);
        $now = new \DateTimeImmutable();
        foreach (['F-001' => 'Sotumag', 'F-002' => 'Quincaillerie Sfax', 'F-003' => 'Visserie du Sud'] as $number => $name) {
            $this->em()->persist(Vendor::create($this->company, $number, new VendorProfile($name), $now));
        }
        foreach (['P-1' => 'Vis 6x40', 'P-2' => 'Cheville 8'] as $reference => $name) {
            $this->em()->persist(Product::create($this->company, $reference, new ProductDetails($name, null, ProductKind::Goods, '1'), $unit, null, [], $now));
        }
        $this->em()->flush();
    }

    public function testEachRowLinksItsProductToItsVendorWithTheVendorsCodeWhenGiven(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import(self::HEADER."P-1,F-001,\nP-1,F-002,QS-12\nP-2,F-002,\n");

        self::assertResponseIsSuccessful();
        self::assertSame([[2, 3, 4], []], [$this->json()['created'], $this->json()['rejected']], json_encode($this->json()['rejected']) ?: '');
        $this->em()->clear();
        self::assertSame(['F-001', 'F-002'], $this->vendorsOf('P-1'));
        self::assertSame([['supplier', 'QS-12', 1, 'F-002']], $this->codesOf('P-1'));
        self::assertSame(['F-002'], $this->vendorsOf('P-2'));
        self::assertSame([], $this->codesOf('P-2'));
    }

    public function testAPreviewWritesNothing(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import(self::HEADER."P-1,F-001,SF-1\n", dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame([2], $this->json()['created']);
        $this->em()->clear();
        self::assertSame([[], []], [$this->vendorsOf('P-1'), $this->codesOf('P-1')]);
    }

    /**
     * A second code of a vendor is added beside its first; a code the product holds under another of its vendors moves
     * to the one the row names, which stays linked to the first; the same file again changes nothing.
     */
    public function testAnUpsertAddsACodeMovesOneAndRemovesNothing(): void
    {
        $this->held('P-1', 'F-001', 'SF-1');
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);
        $file = self::HEADER."P-1,F-001,SF-2\nP-1,F-002,SF-1\nP-2,F-003,\n";

        $this->import($file, mode: 'upsert');

        self::assertResponseIsSuccessful();
        self::assertSame([[3, 4], [2], [], []], [$this->json()['created'], $this->json()['updated'], $this->json()['unchanged'], $this->json()['rejected']], json_encode($this->json()) ?: '');
        $this->em()->clear();
        self::assertSame(['F-001', 'F-002'], $this->vendorsOf('P-1'));
        self::assertSame([['supplier', 'SF-1', 1, 'F-002'], ['supplier', 'SF-2', 1, 'F-001']], $this->codesOf('P-1'));
        self::assertSame(['F-003'], $this->vendorsOf('P-2'));

        $this->import($file, mode: 'upsert', dryRun: true);
        self::assertSame([[], [], [2, 3, 4]], [$this->json()['created'], $this->json()['updated'], $this->json()['unchanged']]);
    }

    /** In "create" mode a product already bought from the vendor is said so, as every subject says it of a known row. */
    public function testCreatingAPairAlreadyLinkedIsRefused(): void
    {
        $this->held('P-1', 'F-001', 'SF-1');
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import(self::HEADER."P-1,F-001,SF-9\n");

        $rejected = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($rejected[0] ?? null);
        self::assertSame(['vendor_number', 'already_exists'], [$rejected[0]['column'] ?? null, $rejected[0]['code'] ?? null]);
        $this->em()->clear();
        self::assertSame([['supplier', 'SF-1', 1, 'F-001']], $this->codesOf('P-1'));
    }

    /** @return iterable<string, array{string, string, string, array<string, int|string>}> */
    public static function refusedRows(): iterable
    {
        yield 'a product the company does not have' => ['P-404,F-001,', 'product_reference', 'unknown_product', ['reference' => 'P-404']];
        yield 'a vendor the company does not have' => ['P-1,F-404,', 'vendor_number', 'unknown_vendor', ['number' => 'F-404']];
        yield 'no product' => [',F-001,', 'product_reference', 'value_required', []];
        yield 'no vendor' => ['P-1,,SF-1', 'vendor_number', 'value_required', []];
        yield 'a code another product holds' => ['P-1,F-001,SF-2', 'vendor_code', 'barcode_taken', ['reference' => 'P-2']];
    }

    /** @param array<string, int|string> $params */
    #[DataProvider('refusedRows')]
    public function testARefusedRowNamesItsCell(string $row, string $column, string $code, array $params): void
    {
        $this->held('P-2', 'F-002', 'SF-2');
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import(self::HEADER.$row."\n", dryRun: true);

        self::assertResponseIsSuccessful();
        $rejected = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($rejected[0] ?? null);
        self::assertSame([$column, $code, $params], [$rejected[0]['column'] ?? null, $rejected[0]['code'] ?? null, $rejected[0]['params'] ?? null]);
    }

    /**
     * Who a product is bought from is read with the product, so whoever writes products links them; a vendor's own code
     * is read with costs, so its column is not offered to anybody else.
     */
    public function testWhoSeesNoCostLinksVendorsButIsOfferedNoCodeColumn(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->client->request('GET', $this->companyPath().'/imports/product-vendors', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertSame(['product_reference', 'vendor_number'], array_map(static fn (mixed $column): mixed => \is_array($column) ? $column['key'] ?? null : null, $this->arrayAt($this->json(), 'columns')));

        $this->import("product_reference,vendor_number\nP-1,F-001\n");
        self::assertSame([2], $this->json()['created'] ?? null, json_encode($this->json()) ?: '');
        $this->em()->clear();
        self::assertSame(['F-001'], $this->vendorsOf('P-1'));

        $this->import(self::HEADER."P-2,F-001,SF-1\n");
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['unknown_columns', ['vendor_code']], [$this->json()['error'] ?? null, $this->json()['columns'] ?? null]);
    }

    /** A company that keeps no vendors has none to name. */
    public function testWithTheVendorsSwitchedOffThereIsNothingToImport(): void
    {
        $this->em()->persist(ModuleState::of($this->company, 'vendors', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->client->request('GET', $this->companyPath().'/imports/product-vendors', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheGuideNamesTheColumnsAndThePairARowIsFoundBy(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->client->request('GET', $this->companyPath().'/imports/product-vendors', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $columns = array_map(static fn (mixed $column): array => \is_array($column) ? [$column['key'] ?? null, $column['required'] ?? null] : [], $this->arrayAt($this->json(), 'columns'));
        self::assertSame([['product_reference', true], ['vendor_number', true], ['vendor_code', false]], $columns);
        self::assertSame(['product_reference', 'vendor_number'], $this->json()['identity'] ?? null);
    }

    private function held(string $reference, string $number, string $code): void
    {
        $product = $this->em()->getRepository(Product::class)->findOneBy(['reference' => $reference]);
        $vendor = $this->em()->getRepository(Vendor::class)->findOneBy(['number' => $number]);
        self::assertInstanceOf(Product::class, $product);
        self::assertInstanceOf(Vendor::class, $vendor);
        $product->replaceBarcodes([new BarcodeLine(BarcodeRole::Supplier, new Barcode($code), 1, $vendor)], new \DateTimeImmutable());
        $this->em()->flush();
    }

    /** @return list<string> */
    private function vendorsOf(string $reference): array
    {
        return array_map(static fn (Vendor $vendor): string => $vendor->getNumber(), $this->product($reference)->getVendors());
    }

    /** @return list<array{string, string, int, string|null}> by code */
    private function codesOf(string $reference): array
    {
        $codes = array_map(static fn (ProductBarcode $row): array => [$row->getRole()->value, $row->getCode(), $row->getQuantity(), $row->getSupplier()?->getNumber()], $this->product($reference)->getBarcodes());
        usort($codes, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return $codes;
    }

    private function product(string $reference): Product
    {
        $product = $this->em()->getRepository(Product::class)->findOneBy(['reference' => $reference]);
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    private function import(string $contents, string $mode = 'create', bool $dryRun = false): void
    {
        $this->uploadFile($this->companyPath().'/imports/product-vendors', 'product-vendors.csv', $contents, 'file', ['mode' => $mode, 'dryRun' => $dryRun ? '1' : '0']);
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
}

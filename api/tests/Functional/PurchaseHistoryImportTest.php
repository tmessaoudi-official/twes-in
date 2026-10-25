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
use App\Tenancy\Domain\Model\Company;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A shop's past purchases, from a file: each line is kept in its product's cost history, naming the vendor it was
 * bought from, its day when the file gives one that can be, its invoice and quantity. It is history only: the product's
 * cost, its price and its stock stay as they are, and « Annuler cet import » takes the lines back.
 */
final class PurchaseHistoryImportTest extends ApiTestCase
{
    private const string HEADER = "product_reference,vendor_number,date,invoice_number,quantity,unit_cost\n";

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('H87', $this->company->getId());
        self::assertNotNull($unit);
        $now = new \DateTimeImmutable();
        foreach (['F-001' => 'Sotumag', 'F-002' => 'Quincaillerie Sfax'] as $number => $name) {
            $this->em()->persist(Vendor::create($this->company, $number, new VendorProfile($name), $now));
        }
        foreach (['P-1' => 'Vis 6x40', 'P-2' => 'Cheville 8'] as $reference => $name) {
            $this->em()->persist(Product::create($this->company, $reference, new ProductDetails($name, null, ProductKind::Goods, '1'), $unit, null, [], $now));
        }
        $this->em()->flush();
    }

    public function testEachLineJoinsItsProductsHistoryNamingItsVendorTheUndatedLast(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import(self::HEADER
            ."P-1,F-001,2024-02-03,FA-12,72,1.6882\n"
            ."P-1,F-002,2025-06-10,*,6,1.7\n"
            ."P-1,F-001,,,3,1.9\n"
            ."P-2,F-002,2026-02-17,DV000210,-1,116.3102\n");

        self::assertResponseIsSuccessful();
        self::assertSame([[2, 3, 4, 5], []], [$this->json()['created'], $this->json()['rejected']], json_encode($this->json()['rejected']) ?: '');
        $history = $this->historyOf('P-1');
        self::assertSame([
            ['purchase', '1.7000', '2025-06-10', 'F-002', 'Quincaillerie Sfax', '*', '6.000'],
            ['purchase', '1.6882', '2024-02-03', 'F-001', 'Sotumag', 'FA-12', '72.000'],
            ['purchase', '1.9000', null, 'F-001', 'Sotumag', null, '3.000'],
        ], $history);
        // A return is a purchase line too, its quantity as written.
        self::assertSame([['purchase', '116.3102', '2026-02-17', 'F-002', 'Quincaillerie Sfax', 'DV000210', '-1.000']], $this->historyOf('P-2'));
        // History only: the product's own cost, price and stock are not touched.
        $this->em()->clear();
        $product = $this->product('P-1');
        self::assertNull($product->getDetails()->costPrice);
        self::assertSame('1.0000', $product->getDetails()->unitPriceNet);
    }

    /** A day no calendar holds, or one far before any shop's books or after today, is kept as no day: nothing is invented. */
    public function testADayThatCannotBeIsKeptAsNoDayAndSaidSo(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);
        $future = (new \DateTimeImmutable('+2 years'))->format('Y-m-d');

        $this->import(self::HEADER."P-1,F-001,0202-09-30,3,1,4.137\nP-1,F-001,2025-13-45,3,1,4.137\nP-1,F-001,{$future},3,1,4.137\n");

        self::assertResponseIsSuccessful();
        self::assertSame([[2, 3, 4], []], [$this->json()['created'], $this->json()['rejected']], json_encode($this->json()['rejected']) ?: '');
        $notes = array_map(static fn (mixed $note): array => \is_array($note) ? [$note['line'] ?? null, $note['column'] ?? null, $note['code'] ?? null, $note['params'] ?? null] : [], $this->arrayAt($this->json(), 'notes'));
        self::assertSame([
            [2, 'date', 'date_kept_unknown', ['date' => '0202-09-30']],
            [3, 'date', 'date_kept_unknown', ['date' => '2025-13-45']],
            [4, 'date', 'date_kept_unknown', ['date' => $future]],
        ], $notes);
        self::assertSame([null, null, null], array_column($this->historyOf('P-1'), 2));
    }

    /** @return iterable<string, array{string, string, string, array<string, int|string>}> */
    public static function refusedRows(): iterable
    {
        yield 'a product the company does not have' => ['P-9,F-001,,,1,2', 'product_reference', 'unknown_product', ['reference' => 'P-9']];
        yield 'a vendor the company does not have' => ['P-1,F-009,,,1,2', 'vendor_number', 'unknown_vendor', ['number' => 'F-009']];
        yield 'no cost' => ['P-1,F-001,,,1,', 'unit_cost', 'value_required', []];
        yield 'a cost below zero' => ['P-1,F-001,,,1,-2', 'unit_cost', 'invalid_price', []];
        yield 'a quantity that is no number' => ['P-1,F-001,,,douze,2', 'quantity', 'invalid_quantity', []];
        yield 'an invoice number longer than kept' => ['P-1,F-001,,'.str_repeat('9', 65).',1,2', 'invoice_number', 'invalid_text', ['max' => 64]];
    }

    /** @param array<string, int|string> $params */
    #[DataProvider('refusedRows')]
    public function testARefusedRowNamesItsCell(string $row, string $column, string $code, array $params): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->import(self::HEADER.$row."\n");

        $rejected = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($rejected[0] ?? null);
        self::assertSame([$column, $code, $params], [$rejected[0]['column'] ?? null, $rejected[0]['code'] ?? null, $rejected[0]['params'] ?? null]);
    }

    public function testAPreviewWritesNothingAndAnUndoTakesTheLinesBack(): void
    {
        $this->signedIn(['company.read', 'product.read', 'product.write', 'product.cost.read']);
        $file = self::HEADER."P-1,F-001,2024-02-03,FA-12,72,1.6882\nP-2,F-002,,,1,2\n";

        $this->import($file, dryRun: true);
        self::assertSame([2, 3], $this->json()['created']);
        self::assertSame([], $this->historyOf('P-1'));

        $this->import($file);
        $run = $this->stringAt($this->json(), 'run');
        self::assertCount(1, $this->historyOf('P-1'));

        $this->stepUp('password-1234');
        $this->postJson($this->companyPath().'/import-runs/'.$run.'/undo', null);

        self::assertResponseIsSuccessful();
        self::assertSame([[], []], [$this->historyOf('P-1'), $this->historyOf('P-2')]);
    }

    /** A vendor merged into another takes its past purchases with it: each line names the vendor kept, and back on an undo. */
    public function testAMergedVendorsPurchasesNameTheVendorKept(): void
    {
        $this->signedIn(['company.read', 'vendor.read', 'vendor.write', 'product.read', 'product.write', 'product.cost.read']);
        $this->import(self::HEADER."P-1,F-002,2025-06-10,*,6,1.7\n");
        self::assertSame([2], $this->json()['created']);
        $kept = $this->vendorId('F-001');

        $this->stepUp('password-1234');
        $this->postJson($this->companyPath().'/record-merges', ['subject' => 'vendor', 'keptId' => $kept, 'ids' => [$this->vendorId('F-002')]]);
        self::assertResponseStatusCodeSame(201);
        $merge = $this->stringAt($this->json(), 'id');

        self::assertSame([['purchase', '1.7000', '2025-06-10', 'F-001', 'Sotumag', '*', '6.000']], $this->historyOf('P-1'));
        $this->postJson($this->companyPath().'/record-removals/'.$merge.'/restore', null);
        self::assertResponseIsSuccessful();
        self::assertSame('F-002', $this->historyOf('P-1')[0][3]);
    }

    /** A vendor's « Achats » lists what was bought from it, line by line, the newest day first and the undated last. */
    public function testAVendorListsWhatWasBoughtFromItAPageAtATime(): void
    {
        $this->signedIn(['company.read', 'vendor.read', 'product.read', 'product.write', 'product.cost.read']);
        $this->import(self::HEADER
            ."P-1,F-001,2024-02-03,FA-12,72,1.6882\n"
            ."P-2,F-001,,,3,1.9\n"
            ."P-2,F-001,2025-06-10,*,6,1.7\n"
            ."P-1,F-002,2026-01-05,,1,2\n");
        self::assertSame([2, 3, 4, 5], $this->json()['created']);

        $this->getJson($this->companyPath().'/vendors/'.$this->vendorId('F-001').'/purchases');

        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->jsonPage()['totalItems']);
        self::assertSame([
            ['P-2', 'Cheville 8', '2025-06-10', '*', '6.000', '1.7000'],
            ['P-1', 'Vis 6x40', '2024-02-03', 'FA-12', '72.000', '1.6882'],
            ['P-2', 'Cheville 8', null, null, '3.000', '1.9000'],
        ], array_map(static fn (array $row): array => [$row['productReference'] ?? null, $row['productName'] ?? null, $row['purchasedOn'] ?? null, $row['invoiceNumber'] ?? null, $row['quantity'] ?? null, $row['unitCost'] ?? null], $this->jsonList()));
        self::assertSame($this->product('P-2')->getId()->toRfc4122(), $this->jsonList()[0]['productId'] ?? null);

        $this->getJson($this->companyPath().'/vendors/'.$this->vendorId('F-001').'/purchases?itemsPerPage=2&page=2');
        self::assertSame([null], array_map(static fn (array $row): mixed => \array_key_exists('purchasedOn', $row) ? $row['purchasedOn'] : 'absent', $this->jsonList()));
        // A page reads its products with its lines: as many statements for one line as for three.
        self::assertSame(
            $this->statementsForAPageOf($this->companyPath().'/vendors/'.$this->vendorId('F-001').'/purchases', 1),
            $this->statementsForAPageOf($this->companyPath().'/vendors/'.$this->vendorId('F-001').'/purchases', 3),
        );
    }

    /** What a vendor was paid for a thing is a cost: without the right to read costs, there is no such page. */
    public function testAVendorsPurchasesAreACost(): void
    {
        $this->signedIn(['company.read', 'vendor.read', 'product.read']);

        $this->getJson($this->companyPath().'/vendors/'.$this->vendorId('F-001').'/purchases');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnotherCompanysVendorHasNoPurchasesHere(): void
    {
        $other = $this->createCompany('Ailleurs');
        $this->em()->persist(Vendor::create($other, 'F-777', new VendorProfile('Autre'), new \DateTimeImmutable()));
        $this->em()->flush();
        $this->signedIn(['company.read', 'vendor.read', 'product.read', 'product.cost.read']);

        $elsewhere = $this->em()->getConnection()->fetchOne('SELECT id::text FROM vendor WHERE number = ?', ['F-777']);
        self::assertIsString($elsewhere);

        $this->getJson($this->companyPath().'/vendors/'.$elsewhere.'/purchases');

        self::assertResponseStatusCodeSame(404);
    }

    /** What a product cost is read only with the right to read costs, and so is what it was bought for. */
    public function testWhoSeesNoCostIsOfferedNothingToImport(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->client->request('GET', $this->companyPath().'/imports/purchase-history', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testWithTheVendorsSwitchedOffThereIsNothingToImport(): void
    {
        $this->em()->persist(ModuleState::of($this->company, 'vendors', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->client->request('GET', $this->companyPath().'/imports/purchase-history', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheGuideNamesTheColumns(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);

        $this->client->request('GET', $this->companyPath().'/imports/purchase-history', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $columns = array_map(static fn (mixed $column): array => \is_array($column) ? [$column['key'] ?? null, $column['required'] ?? null] : [], $this->arrayAt($this->json(), 'columns'));
        self::assertSame([['product_reference', true], ['vendor_number', true], ['date', false], ['invoice_number', false], ['quantity', false], ['unit_cost', true]], $columns);
    }

    /** @return list<array{string, string|null, string|null, string|null, string|null, string|null, string|null}> */
    private function historyOf(string $reference): array
    {
        $this->client->request('GET', $this->companyPath().'/products/'.$this->product($reference)->getId()->toRfc4122().'/cost-history', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        return array_map(static fn (array $row): array => [
            \is_string($row['source'] ?? null) ? $row['source'] : '',
            \is_string($row['newCost'] ?? null) ? $row['newCost'] : null,
            \is_string($row['purchasedOn'] ?? null) ? $row['purchasedOn'] : null,
            \is_string($row['vendorNumber'] ?? null) ? $row['vendorNumber'] : null,
            \is_string($row['vendorName'] ?? null) ? $row['vendorName'] : null,
            \is_string($row['invoiceNumber'] ?? null) ? $row['invoiceNumber'] : null,
            \is_string($row['quantity'] ?? null) ? $row['quantity'] : null,
        ], $this->jsonList());
    }

    private function vendorId(string $number): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT id::text FROM vendor WHERE number = ? AND company_id = ?', [$number, $this->company->getId()->toRfc4122()]);
        self::assertIsString($id);

        return $id;
    }

    private function product(string $reference): Product
    {
        $product = $this->em()->getRepository(Product::class)->findOneBy(['reference' => $reference]);
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    private function import(string $contents, bool $dryRun = false): void
    {
        $this->uploadFile($this->companyPath().'/imports/purchase-history', 'achats.csv', $contents, 'file', ['mode' => 'create', 'dryRun' => $dryRun ? '1' : '0']);
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

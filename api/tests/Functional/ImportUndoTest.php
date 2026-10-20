<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Domain\ValueObject\StockLossReason;
use App\Module\Products\Application\BarcodeInput;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\BarcodeRole;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * « Annuler cet import »: what an import created that nothing uses yet goes, the way a delete takes it, and what it
 * keeps is said; the stock it moved is taken back where nothing came to stand on it since; a day, once, with the proof.
 */
final class ImportUndoTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    private Company $company;

    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('H87', $this->company->getId());
        self::assertNotNull($unit);
        $now = new \DateTimeImmutable();
        $product = Product::create($this->company, 'P-OLD', new ProductDetails('Marteau', null, ProductKind::Goods, '12.000'), $unit, null, [$this->taxId('TVA19')], $now);
        $this->em()->persist($product);
        foreach (['F-001' => 'Sotumag', 'F-002' => 'Quincaillerie Sfax'] as $number => $name) {
            $this->em()->persist(Vendor::create($this->company, $number, new VendorProfile($name), $now));
        }
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();
    }

    public function testWhatNothingUsesGoesWhatSomethingUsesIsKeptAndSaidAndTheBinPutsItBack(): void
    {
        $this->signedIn(['customer.read', 'customer.write', 'quote.read', 'quote.write', 'product.read']);
        $run = $this->imported('customers', "number,name\nCLI-0001,Carthage Conseil\nCLI-0002,Sonia Ben Ali\nCLI-0003,Atlas Bois\n");
        $this->postJson($this->path('quotes'), ['customerId' => $this->customerId('CLI-0002'), 'establishmentId' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'lines' => [['productId' => $this->productId, 'quantity' => '1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->undo($run);

        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertSame(['CLI-0001 Carthage Conseil', 'CLI-0003 Atlas Bois'], array_column($this->arrayAt($answer, 'removed'), 'label'));
        self::assertSame([['label' => 'CLI-0002 Sonia Ben Ali', 'uses' => [['table' => 'quote', 'column' => 'customer_id', 'count' => 1]]]], $this->fields($this->arrayAt($answer, 'kept'), 'label', 'uses'));
        self::assertSame(['CLI-0002'], $this->customerNumbers());
        self::assertSame('import', $this->em()->getConnection()->fetchOne('SELECT kind FROM record_removal WHERE id = ?', [$this->stringAt($answer, 'removal')]));
        self::assertSame(1, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'import.undone' AND entity_id = ?", [$run]));

        $this->getJson($this->path('import-runs'));
        self::assertResponseIsSuccessful();
        [$listed] = $this->jsonList();
        self::assertSame([$run, 'customers', 3, 0], [$listed['id'], $listed['subject'], $listed['created'], $listed['updated']]);
        self::assertSame('Shop', $listed['by'], 'the list names who ran the import');
        self::assertNotNull($listed['undoneAt']);

        // Once is enough: the next undo is refused.
        $this->undo($run);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('import_undone', $this->json()['error']);

        // « Récemment supprimés » puts it back, and the import reads as never undone.
        $this->postJson($this->path('record-removals/'.$this->stringAt($answer, 'removal').'/restore'), null);
        self::assertResponseIsSuccessful();
        self::assertSame(['CLI-0001', 'CLI-0002', 'CLI-0003'], $this->customerNumbers());
        $this->getJson($this->path('import-runs'));
        self::assertNull($this->jsonList()[0]['undoneAt']);
    }

    public function testAnUndoTakesTheProofGivenAgainAndTheSubjectsWriteRight(): void
    {
        $this->signedIn(['customer.read', 'customer.write'], prove: false);
        $run = $this->imported('customers', "number,name\nCLI-0001,Carthage Conseil\n");

        $this->postJson($this->path('import-runs/'.$run.'/undo'), null);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error']);
        self::assertSame(['CLI-0001'], $this->customerNumbers());

        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $this->createUser('reader@twes.local', self::PASSWORD, $company, ['company.read', 'customer.read'], 'reader');
        $this->login('reader@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        // Whoever may not write the subject meets the 404 a stranger does, as every delete answers.
        $this->postJson($this->path('import-runs/'.$run.'/undo'), null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path('import-runs'));
        self::assertSame([], $this->jsonList(), 'an import into a subject the person may not write is not listed');
        self::assertSame(['CLI-0001'], $this->customerNumbers());
    }

    public function testAnImportIsUndoneOnlyWithinItsDay(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $run = $this->imported('customers', "number,name\nCLI-0001,Carthage Conseil\n");

        // The import made a day and a minute ago; the session stays as it is.
        $this->em()->getConnection()->executeStatement("UPDATE import_run SET at = at - INTERVAL '24 hours 1 minute' WHERE id = ?", [$run]);
        $this->undo($run);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('import_final', $this->json()['error']);
        self::assertSame(['CLI-0001'], $this->customerNumbers());
    }

    public function testAnotherCompanysImportIsNotFound(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->undo(Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * A product the file created goes with the stock the file gave it; the stock it added to a product already there is
     * taken back by a movement of its own, which the bin's restore takes back again.
     */
    public function testAProductGoesWithItsStockAndStockAddedToAProductAlreadyThereIsTakenBack(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read', 'stock.read', 'stock.write']);
        $run = $this->imported('products', "reference,name,unit_code,unit_price_net,cost_price,stock_add\nVIS-NEW,Vis neuve,H87,1,0.2,5\nP-OLD,Marteau,H87,12,4,3\n", 'upsert');
        self::assertSame(['3.000', '5.000'], [$this->onHand('P-OLD'), $this->onHand('VIS-NEW')]);

        $this->undo($run);

        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertSame(['VIS-NEW Vis neuve'], array_column($this->arrayAt($answer, 'removed'), 'label'));
        self::assertSame([], $this->arrayAt($answer, 'kept'));
        self::assertSame(1, $answer['updated'], 'the product the file changed stays as it was changed');
        self::assertSame([['reference' => 'P-OLD', 'quantity' => '3']], $this->fields($this->arrayAt($answer, 'stockTakenBack'), 'reference', 'quantity'));
        self::assertSame([], $this->arrayAt($answer, 'stockKept'));
        self::assertSame(['P-OLD'], $this->productReferences());
        self::assertSame('0.000', $this->onHand('P-OLD'));
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM stock_movement m JOIN product p ON p.id = m.product_id WHERE p.reference = ?', ['VIS-NEW']));
        self::assertSame('0.0000000', $this->value('P-OLD'), 'what came in leaves at what it came in at');
        self::assertSame(['4.0000000'], $this->em()->getConnection()->fetchFirstColumn('SELECT DISTINCT m.unit_cost::numeric(20,7)::text FROM stock_movement m JOIN product p ON p.id = m.product_id WHERE p.reference = ?', ['P-OLD']), 'the reversal leaves at the cost the goods came in at');

        $this->postJson($this->path('record-removals/'.$this->stringAt($answer, 'removal').'/restore'), null);

        self::assertResponseIsSuccessful();
        self::assertSame(['P-OLD', 'VIS-NEW'], $this->productReferences());
        self::assertSame(['3.000', '5.000'], [$this->onHand('P-OLD'), $this->onHand('VIS-NEW')]);
    }

    /** A count taken after the undo stands on what it took back: the bin refuses to restore it, and nothing comes back. */
    public function testACountSinceTheUndoRefusesTheRestore(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read', 'stock.read', 'stock.write']);
        $run = $this->imported('products', "reference,name,unit_code,unit_price_net,cost_price,stock_add\nVIS-NEW,Vis neuve,H87,1,0.2,5\nP-OLD,Marteau,H87,12,4,3\n", 'upsert');
        $this->undo($run);
        self::assertResponseIsSuccessful();
        $removal = $this->stringAt($this->json(), 'removal');
        $place = $this->em()->getConnection()->fetchOne('SELECT m.location_id FROM stock_movement m JOIN product p ON p.id = m.product_id WHERE p.reference = ? LIMIT 1', ['P-OLD']);
        self::assertIsString($place);
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        static::getContainer()->get(KeepStock::class)->count($company, Uuid::fromString($this->productId), Uuid::fromString($place), '2', null);

        $this->postJson($this->path('record-removals/'.$removal.'/restore'), null);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['restore_conflict', 'stock_movement'], [$this->json()['error'], $this->json()['table']]);
        self::assertSame(['P-OLD'], $this->productReferences());
        self::assertSame('2.000', $this->onHand('P-OLD'));
        $this->getJson($this->path('import-runs'));
        self::assertNotNull($this->jsonList()[0]['undoneAt'], 'the import stays taken back');
    }

    /**
     * Goods received since at another cost move the average away from what the file brought them in at: the undo takes
     * them back at their own cost, so what stays is valued at what it came in at.
     */
    public function testStockIsTakenBackAtTheCostItCameInAtNotTheAverage(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read', 'stock.read', 'stock.write']);
        $run = $this->imported('products', "reference,name,unit_code,unit_price_net,cost_price,stock_add\nP-OLD,Marteau,H87,12,4,3\n", 'upsert');
        $place = $this->em()->getConnection()->fetchOne('SELECT m.location_id FROM stock_movement m JOIN product p ON p.id = m.product_id WHERE p.reference = ? LIMIT 1', ['P-OLD']);
        self::assertIsString($place);
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        static::getContainer()->get(KeepStock::class)->receive($company, Uuid::fromString($this->productId), Uuid::fromString($place), '2', null, unitCost: '10');
        self::assertSame('32.0000000', $this->value('P-OLD'));

        $this->undo($run);

        self::assertResponseIsSuccessful();
        self::assertSame('2.000', $this->onHand('P-OLD'));
        self::assertSame('20.0000000', $this->value('P-OLD'), 'the goods received since stay at their own cost');
    }

    /** Goods that went out since, or a count since, stand on the stock the file brought: it stays, and why is said. */
    public function testStockSomethingStandsOnSinceIsKeptAndSaid(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read', 'stock.read', 'stock.write']);
        $run = $this->imported('opening-stock', "reference,stock_add\nP-OLD,5\n");
        $place = $this->em()->getConnection()->fetchOne('SELECT location_id FROM stock_movement LIMIT 1');
        self::assertIsString($place);
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        static::getContainer()->get(KeepStock::class)->writeOff($company, Uuid::fromString($this->productId), Uuid::fromString($place), '4', StockLossReason::Broken, null, null);

        $this->undo($run);

        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertNull($answer['removal'], 'an opening stock creates no record: nothing goes to the bin');
        self::assertSame([], $this->arrayAt($answer, 'stockTakenBack'));
        self::assertSame([['reference' => 'P-OLD', 'reason' => 'below_zero']], $this->fields($this->arrayAt($answer, 'stockKept'), 'reference', 'reason'));
        self::assertSame('1.000', $this->onHand('P-OLD'));
    }

    public function testAnOpeningStockIsTakenBackWhereNothingMovedSince(): void
    {
        $this->signedIn(['product.read', 'stock.read', 'stock.write']);
        $run = $this->imported('opening-stock', "reference,stock_add\nP-OLD,5\n");

        $this->undo($run);

        self::assertResponseIsSuccessful();
        self::assertSame(['P-OLD'], array_column($this->arrayAt($this->json(), 'stockTakenBack'), 'reference'));
        self::assertSame('0.000', $this->onHand('P-OLD'));

        // Taken back, the same file is no longer one already imported.
        $this->import('opening-stock', "reference,stock_add\nP-OLD,5\n", 'create', dryRun: true);
        self::assertNull($this->json()['alreadyImportedAt']);
    }

    /** A link the file added goes back off its product, with the vendor's own code the file wrote for it. */
    public function testTheLinksAFileAddedGoWithTheCodesItWrote(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);
        $run = $this->imported('product-vendors', "product_reference,vendor_number,vendor_code\nP-OLD,F-001,\nP-OLD,F-002,QS-12\n");
        self::assertSame(['F-001', 'F-002'], $this->vendorsOf('P-OLD'));

        $this->undo($run);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->vendorsOf('P-OLD'));
        self::assertSame(0, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM product_barcode WHERE code = 'QS-12'"));
    }

    /** A vendor given a code of the product since the file linked it stands on that link: it stays, and why is said. */
    public function testALinkWhoseVendorHoldsACodeSinceIsKeptAndSaid(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);
        $run = $this->imported('product-vendors', "product_reference,vendor_number,vendor_code\nP-OLD,F-001,\nP-OLD,F-002,\n");
        $vendor = $this->em()->getConnection()->fetchOne('SELECT id FROM vendor WHERE number = ?', ['F-001']);
        self::assertIsString($vendor);
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        static::getContainer()->get(ManageProducts::class)->replaceBarcodes($company, Uuid::fromString($this->productId), [new BarcodeInput(BarcodeRole::Supplier->value, 'SOT-77', 1, Uuid::fromString($vendor))], null);

        $this->undo($run);

        self::assertResponseIsSuccessful();
        self::assertSame([['label' => 'P-OLD F-001', 'reason' => 'vendor_has_code']], $this->fields($this->arrayAt($this->json(), 'rowsKept'), 'label', 'reason'));
        self::assertSame(['F-001'], $this->vendorsOf('P-OLD'), 'the link the code stands on stays, the other goes');
        self::assertSame(1, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM product_barcode WHERE code = 'SOT-77'"));
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions, bool $prove = true): void
    {
        $this->createUser('shop@twes.local', self::PASSWORD, $this->company, ['company.read', ...$permissions], 'member');
        $this->login('shop@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();
        if ($prove) {
            $this->stepUp(self::PASSWORD);
        }
    }

    /** Imports the file, committed, and answers the import's id. */
    private function imported(string $subject, string $contents, string $mode = 'create'): string
    {
        $this->import($subject, $contents, $mode);
        self::assertResponseIsSuccessful();
        $report = $this->json();
        self::assertSame([true, []], [$report['committed'], $report['rejected']], json_encode($report['rejected']) ?: '');

        return $this->stringAt($report, 'run');
    }

    private function import(string $subject, string $contents, string $mode, bool $dryRun = false): void
    {
        $this->uploadFile($this->path('imports/'.$subject), $subject.'.csv', $contents, 'file', ['mode' => $mode, 'dryRun' => $dryRun ? '1' : '0']);
    }

    private function undo(string $run): void
    {
        $this->postJson($this->path('import-runs/'.$run.'/undo'), null);
    }

    /**
     * The named fields of each row of a list, in that order.
     *
     * @param array<mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function fields(array $rows, string ...$names): array
    {
        $out = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $out[] = array_combine($names, array_map(static fn (string $name): mixed => $row[$name] ?? null, $names));
        }

        return $out;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            self::assertIsString($value);
            $out[] = $value;
        }

        return $out;
    }

    private function customerId(string $number): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT id FROM customer WHERE number = ?', [$number]);
        self::assertIsString($id);

        return $id;
    }

    /** @return list<string> */
    private function customerNumbers(): array
    {
        return self::strings($this->em()->getConnection()->fetchFirstColumn('SELECT number FROM customer ORDER BY number'));
    }

    /** @return list<string> */
    private function productReferences(): array
    {
        return self::strings($this->em()->getConnection()->fetchFirstColumn('SELECT reference FROM product ORDER BY reference'));
    }

    /** @return list<string> */
    private function vendorsOf(string $reference): array
    {
        return self::strings($this->em()->getConnection()->fetchFirstColumn('SELECT v.number FROM product_vendor pv JOIN vendor v ON v.id = pv.vendor_id JOIN product p ON p.id = pv.product_id WHERE p.reference = ? ORDER BY v.number', [$reference]));
    }

    private function onHand(string $reference): string
    {
        $sum = $this->em()->getConnection()->fetchOne('SELECT COALESCE(SUM(m.quantity), 0)::numeric(14,3)::text FROM stock_movement m JOIN product p ON p.id = m.product_id WHERE p.reference = ?', [$reference]);
        self::assertIsString($sum);

        return $sum;
    }

    /** What the product's stock is worth, as the valuation sums it. */
    private function value(string $reference): string
    {
        $value = $this->em()->getConnection()->fetchOne('SELECT COALESCE(SUM(CASE WHEN m.unit_cost IS NULL THEN 0 ELSE m.quantity * m.unit_cost + COALESCE(m.revaluation, 0) END), 0)::numeric(20,7)::text FROM stock_movement m JOIN product p ON p.id = m.product_id WHERE p.reference = ?', [$reference]);
        self::assertIsString($value);

        return $value;
    }

    private function taxId(string $code): Uuid
    {
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax->getId();
    }

    private function path(string $resource): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource;
    }
}

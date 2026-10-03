<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/** The stock levels and the stock movements lists exported as files, without any cost (docs/SPEC.md § 7, row 60). */
final class StockExportTest extends ApiTestCase
{
    /** A figure only a receipt's cost holds, so finding it anywhere in a file means a cost was written. */
    private const string COST = '555.5555';

    private Company $company;
    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '10'), $piece, null, [], $now);
        $mouse = Product::create($this->company, 'ART-002', new ProductDetails('Souris', null, ProductKind::Goods, '10'), $piece, null, [], $now);
        $this->em()->persist($laptop);
        $this->em()->persist($mouse);
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        $this->createUser('keeper@twes.local', 'password-1234', $this->company, ['stock.read', 'stock.write', 'product.cost.read'], 'keeper');
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['customer.read'], 'sales');
        $this->login('keeper@twes.local', 'password-1234');
        $this->getJson($this->path('/stock-locations'));
        $this->site = $this->stringAt($this->jsonList()[0], 'id');
        $this->receive($laptop->getId()->toRfc4122(), '10', self::COST);
        $this->receive($mouse->getId()->toRfc4122(), '7', null);
    }

    public function testTheStockLevelsAreOneRowPerProductAndLocation(): void
    {
        $lines = $this->csv('/exports/stock-levels.csv');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('stock-levels.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $header = $this->header($lines);
        self::assertSame(['product_reference', 'product', 'unit_code', 'location_code', 'location', 'lot_code', 'lot_expires_on', 'quantity'], $header);
        self::assertCount(3, $lines, 'the header and the two stocks');
        $rows = array_column(array_map(static fn (string $line): array => array_combine($header, str_getcsv($line, escape: '')), \array_slice($lines, 1)), null, 'product_reference');
        self::assertSame(['Portable', 'C62', '10.000'], [$rows['ART-001']['product'], $rows['ART-001']['unit_code'], $rows['ART-001']['quantity']]);
        self::assertSame('7.000', $rows['ART-002']['quantity']);
        self::assertNotSame('', (string) $rows['ART-001']['location_code']);
    }

    public function testTheLevelsSearchAndOrderOfTheScreenNarrowAndSortTheFile(): void
    {
        self::assertCount(2, $this->csv('/exports/stock-levels.csv?q=souris'));
        self::assertCount(3, $this->csv('/exports/stock-levels.csv?locationId='.$this->site));
        self::assertCount(1, $this->csv('/exports/stock-levels.csv?q=nobody-here'), 'the header alone');
        self::assertStringStartsWith('ART-002', $this->csv('/exports/stock-levels.csv?order[reference]=desc')[1]);
    }

    public function testTheMovementsAreOneRowEachAndCarryNoCost(): void
    {
        $lines = $this->csv('/exports/stock-movements.csv');

        self::assertResponseIsSuccessful();
        $header = $this->header($lines);
        self::assertSame(['date', 'product_reference', 'product', 'location_code', 'location', 'kind', 'quantity', 'unit_code', 'source', 'lot_code', 'lot_expires_on', 'reason', 'note'], $header);
        self::assertCount(3, $lines, 'the header and the two receipts');
        $rows = array_column(array_map(static fn (string $line): array => array_combine($header, str_getcsv($line, escape: '')), \array_slice($lines, 1)), null, 'product_reference');
        self::assertSame(['Portable', 'in', '10.000'], [$rows['ART-001']['product'], $rows['ART-001']['kind'], $rows['ART-001']['quantity']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', (string) $rows['ART-001']['date']);
        // The list on screen never shows what a receipt cost, so neither does its file, whoever asks.
        self::assertStringNotContainsString(self::COST, implode("\n", $lines));
        $this->client->request('GET', $this->path('/exports/stock-movements.xlsx'));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::COST, $this->unzipped($this->content()));
    }

    public function testTheMovementsSearchAndFiltersOfTheScreenNarrowTheFile(): void
    {
        self::assertCount(2, $this->csv('/exports/stock-movements.csv?q=souris'));
        self::assertCount(3, $this->csv('/exports/stock-movements.csv?kind=in'));
        self::assertCount(1, $this->csv('/exports/stock-movements.csv?kind=out'), 'nothing left the stock');
        self::assertCount(3, $this->csv('/exports/stock-movements.csv?locationId='.$this->site));
    }

    public function testAnXlsxIsOfferedForTheLevelsToo(): void
    {
        $this->client->request('GET', $this->path('/exports/stock-levels.xlsx'));

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('PK', $this->content(), 'a zip archive');
    }

    public function testWhoCannotReadTheStockIsAnsweredAsAStranger(): void
    {
        $this->login('sales@twes.local', 'password-1234');

        foreach (['stock-levels', 'stock-movements'] as $subject) {
            $this->client->request('GET', $this->path('/exports/'.$subject.'.csv'));
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $subject);
        }
    }

    private function receive(string $productId, string $quantity, ?string $unitCost): void
    {
        $this->postJson($this->path('/stock-movements'), ['operation' => 'receive', 'productId' => $productId, 'locationId' => $this->site, 'quantity' => $quantity, 'unitCost' => $unitCost]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function header(array $lines): array
    {
        return array_map(strval(...), str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), escape: ''));
    }

    /** What every part of an .xlsx archive says, joined, so a figure is found whichever part holds it. */
    private function unzipped(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'twes-xlsx-test-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $text = '';
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $text .= (string) $zip->getFromIndex($index);
        }
        $zip->close();
        unlink($path);

        return $text;
    }

    /** @return list<string> */
    private function csv(string $suffix): array
    {
        $this->client->request('GET', $this->path($suffix));

        return array_values(array_filter(explode("\n", $this->content()), static fn (string $line): bool => '' !== trim($line)));
    }

    private function content(): string
    {
        // A file response is deleted once sent: what the browser got is what the client kept.
        return $this->client->getInternalResponse()->getContent();
    }

    private function path(string $suffix): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().$suffix;
    }
}

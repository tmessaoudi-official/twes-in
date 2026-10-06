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

/** The products list exported as a file, under the columns the import reads, and never showing a cost to who may not read it (docs/SPEC.md § 7, row 60). */
final class ProductExportTest extends ApiTestCase
{
    /** A figure no other column holds, so finding it anywhere in a file means the cost was written. */
    private const string COST = '777.1234';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany('TVA19', $this->company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($tax);
        $now = new \DateTimeImmutable();
        $goods = Product::create($this->company, 'ART-001', new ProductDetails('Portable 14"', 'Un portable', ProductKind::Goods, '1250', self::COST), $unit, null, [$tax->getId()], $now);
        $service = Product::create($this->company, 'SRV-001', new ProductDetails('Pose', null, ProductKind::Service, '40'), $unit, null, [], $now);
        $service->revise('SRV-001', $service->getDetails(), $unit, null, [], false, $now);
        $this->em()->persist($goods);
        $this->em()->persist($service);
        $this->em()->flush();
    }

    public function testAReaderOfCostsGetsTheProductsUnderTheColumnsAnImportTakes(): void
    {
        $this->signIn(['product.read', 'product.cost.read']);

        $lines = $this->csv('/exports/products.csv');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('products.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $header = $this->header($lines);
        foreach (['reference', 'name', 'kind', 'unit_code', 'unit_price_net', 'cost_price', 'barcode', 'default_tax_codes', 'active'] as $column) {
            self::assertContains($column, $header);
        }
        self::assertNotContains('home_location', $header, 'where goods lie is the stock list\'s to say');
        self::assertCount(3, $lines, 'the header and the two products');
        $rows = array_column(array_map(static fn (string $line): array => array_combine($header, str_getcsv($line, escape: '')), \array_slice($lines, 1)), null, 'reference');
        $goods = $rows['ART-001'];
        self::assertSame(['Portable 14"', 'goods', 'C62', '1250.0000', self::COST, 'TVA19', 'yes'], [$goods['name'], $goods['kind'], $goods['unit_code'], $goods['unit_price_net'], $goods['cost_price'], $goods['default_tax_codes'], $goods['active']]);
        self::assertSame(['service', '', 'no'], [$rows['SRV-001']['kind'], $rows['SRV-001']['cost_price'], $rows['SRV-001']['active']]);
    }

    public function testTheSearchTheFiltersAndTheOrderOfTheScreenNarrowAndSortTheFile(): void
    {
        $this->signIn(['product.read']);

        self::assertCount(2, $this->csv('/exports/products.csv?q=portable'));
        self::assertCount(2, $this->csv('/exports/products.csv?kind=service'));
        self::assertCount(2, $this->csv('/exports/products.csv?isActive=false'), 'only the inactive product');
        self::assertCount(1, $this->csv('/exports/products.csv?q=nobody-here'), 'the header alone');
        self::assertStringStartsWith('SRV-001', $this->csv('/exports/products.csv?order[reference]=desc')[1]);
    }

    public function testWhoMayNotReadCostsIsGivenNoCostColumnNorFigureInEitherFormat(): void
    {
        $this->signIn(['product.read']);

        $csv = implode("\n", $this->csv('/exports/products.csv'));
        self::assertNotContains('cost_price', $this->header(explode("\n", $csv)));
        self::assertStringNotContainsString(self::COST, $csv);

        $this->client->request('GET', $this->path().'/exports/products.xlsx');
        self::assertResponseIsSuccessful();
        $archive = $this->unzipped($this->content());
        self::assertStringContainsString('ART-001', $archive, 'the product is in the file');
        self::assertStringNotContainsString(self::COST, $archive, 'its cost is not');
        self::assertStringNotContainsString('cost_price', $archive);
    }

    public function testAReaderOfCostsGetsThemInTheXlsxToo(): void
    {
        $this->signIn(['product.read', 'product.cost.read']);

        $this->client->request('GET', $this->path().'/exports/products.xlsx');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::COST, $this->unzipped($this->content()));
    }

    public function testWhoCannotReadProductsIsAnsweredAsAStranger(): void
    {
        $this->signIn(['customer.read']);

        $this->client->request('GET', $this->path().'/exports/products.csv');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param list<string> $permissions */
    private function signIn(array $permissions): void
    {
        $this->createUser('stock@twes.local', 'password-1234', $this->em()->find(Company::class, $this->company->getId()), $permissions, 'member');
        $this->login('stock@twes.local', 'password-1234');
        // A file waits for the person to have proved who they are again (docs/SPEC.md § 7, audit H-b2).
        $this->stepUp('password-1234');
        self::assertResponseIsSuccessful();
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
        $this->client->request('GET', $this->path().$suffix);

        return array_values(array_filter(explode("\n", $this->content()), static fn (string $line): bool => '' !== trim($line)));
    }

    private function content(): string
    {
        // A file response is deleted once sent: what the browser got is what the client kept.
        return $this->client->getInternalResponse()->getContent();
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}

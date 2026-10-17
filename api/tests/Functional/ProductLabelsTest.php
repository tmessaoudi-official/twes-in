<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Products\Application\ProductLabels;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The labels of the products a person selected, read in one request: each product asked for once, in the order asked,
 * with its codes and what a customer pays for what each code enters, taxes included, as a scan of that code says it.
 * A product this company does not hold is simply not answered, which the sheet names as not found.
 */
final class ProductLabelsTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testEachProductAskedComesOnceInTheOrderAskedWithWhatEachOfItsCodesCosts(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $laptop = $this->aProductWith('ART-001', 'Portable', [['role' => 'unit', 'code' => '3017620422003', 'quantity' => 1], ['role' => 'pack', 'code' => '13017620422000', 'quantity' => 12]], '100', ['FODEC', 'TVA19']);
        $cable = $this->aProductWith('ART-002', 'Câble', [], '10');

        $this->getJson($this->path([$cable, $laptop, Uuid::v7()->toRfc4122(), $laptop, 'nope']));

        self::assertResponseIsSuccessful();
        self::assertSame([
            ['productId' => $cable, 'reference' => 'ART-002', 'name' => 'Câble', 'codes' => []],
            ['productId' => $laptop, 'reference' => 'ART-001', 'name' => 'Portable', 'codes' => [
                ['code' => '3017620422003', 'role' => 'unit', 'quantity' => 1, 'priceGross' => '120.190'],
                ['code' => '13017620422000', 'role' => 'pack', 'quantity' => 12, 'priceGross' => '1442.280'],
            ]],
        ], $this->jsonList());
    }

    public function testAnotherCompanysProductIsNotAnswered(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $mine = $this->aProductWith('ART-001', 'Portable', [], '10');
        $other = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($other);
        $this->createUser('globex@twes.local', 'password-1234', $other, ['product.read', 'product.write'], 'clerk');
        $this->login('globex@twes.local', 'password-1234');
        $theirs = $this->aProductWith('ART-009', 'Leur portable', [], '10', [], $other);
        $this->login('clerk@twes.local', 'password-1234');

        $this->getJson($this->path([$theirs, $mine]));

        self::assertSame([$mine], array_map(fn (array $row): string => $this->stringAt($row, 'productId'), $this->jsonList()));
    }

    public function testNoProductsOrMoreThanAListPageAreRefused(): void
    {
        $this->signedIn(['product.read']);

        $this->getJson($this->path([]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->getJson($this->path(array_map(static fn (): string => Uuid::v7()->toRfc4122(), range(1, 101))));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->getJson($this->path(array_map(static fn (): string => Uuid::v7()->toRfc4122(), range(1, 100))));
        self::assertResponseIsSuccessful();
    }

    public function testTheReadItselfTakesOneToAListPageOfProducts(): void
    {
        $labels = static::getContainer()->get(ProductLabels::class);
        $ids = static fn (int $count): array => array_map(static fn (): Uuid => Uuid::v7(), range(1, $count));
        foreach ([[], $ids(101)] as $asked) {
            try {
                $labels->of($this->company, $asked);
                self::fail(\sprintf('%d products were read.', \count($asked)));
            } catch (\InvalidArgumentException $refused) {
                self::assertSame('A sheet of labels takes 1 to 100 products.', $refused->getMessage());
            }
        }
        // An id named twice counts once.
        $twice = $ids(100);
        self::assertSame([], $labels->of($this->company, [...$twice, $twice[0]]));
    }

    public function testSomebodyWhoCannotReadProductsIsAnsweredAsAStranger(): void
    {
        $this->signedIn(['company.read']);

        $this->getJson($this->path([Uuid::v7()->toRfc4122()]));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @param list<array<string, mixed>> $codes
     * @param list<string>               $taxCodes the company's line taxes the product starts its lines with
     */
    private function aProductWith(string $reference, string $name, array $codes, string $price, array $taxCodes = [], ?Company $company = null): string
    {
        $company ??= $this->company;
        $taxes = static::getContainer()->get(TaxComponentRepository::class);
        $taxIds = array_map(static function (string $code) use ($taxes, $company): string {
            $tax = $taxes->ofCodeInCompany($code, $company->getId());
            self::assertNotNull($tax);

            return $tax->getId()->toRfc4122();
        }, $taxCodes);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($unit);
        $base = '/api/companies/'.$company->getId()->toRfc4122().'/products';
        $this->postJson($base, [
            'reference' => $reference, 'name' => $name, 'description' => null, 'kind' => 'goods',
            'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => $price, 'costPrice' => null, 'categoryId' => null,
            'defaultTaxComponentIds' => $taxIds, 'customFields' => [], 'isActive' => true,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        if ([] !== $codes) {
            $this->sendJson('PUT', "$base/$id/barcodes", ['version' => $this->storedVersion('product', $id), 'barcodes' => $codes]);
            self::assertResponseIsSuccessful();
        }

        return $id;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, $permissions, 'clerk');
        $this->login('clerk@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    /** @param list<string> $ids */
    private function path(array $ids): string
    {
        $query = implode('&', array_map(static fn (string $id): string => 'productId[]='.rawurlencode($id), $ids));

        return '/api/companies/'.$this->company->getId()->toRfc4122().'/product-labels'.('' === $query ? '' : '?'.$query);
    }
}

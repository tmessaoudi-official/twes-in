<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * A scan names one product or none (docs/SPEC.md § 7, 2026-09-20 04:15, 2026-09-22 11:05 and 11:10): the code's
 * role and count come with it, and a GS1 scan is read for its GTIN, lot, use-by date and serial.
 */
final class ProductScanTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testAScanNamesTheProductItsRoleAndHowManyItEnters(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $id = $this->aProductWith([['role' => 'unit', 'code' => '3017620422003', 'quantity' => 1], ['role' => 'pack', 'code' => '13017620422000', 'quantity' => 12]]);

        $this->getJson($this->path('13017620422000'));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['productId' => $id, 'reference' => 'ART-001', 'name' => 'Portable', 'isActive' => true, 'code' => '13017620422000', 'role' => 'pack', 'quantity' => 12, 'lot' => null, 'useBy' => null, 'serial' => null],
            array_intersect_key($this->json(), array_flip(['productId', 'reference', 'name', 'isActive', 'code', 'role', 'quantity', 'lot', 'useBy', 'serial'])),
        );
        // The EAN-13 read with a leading zero is the same code.
        $this->getJson($this->path('03017620422003'));
        self::assertSame(['unit', 1], [$this->json()['role'], $this->json()['quantity']]);
    }

    public function testAScanSaysTheCustomerPriceAndNeverTheCost(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->aProductWith([['role' => 'unit', 'code' => '3017620422003', 'quantity' => 1]]);

        $this->getJson($this->path('3017620422003'));

        $price = $this->stringAt($this->json(), 'unitPriceNet');
        self::assertSame(10.0, (float) $price, 'the price a customer pays for one unit, as the product keeps it');
        self::assertArrayNotHasKey('costPrice', $this->json());
    }

    public function testAGs1ScanIsReadForItsGtinLotUseByAndSerial(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->aProductWith([['role' => 'pack', 'code' => '13017620422000', 'quantity' => 12]]);

        $this->getJson($this->path(']C1011301762042200017270531'."10LOT-7\x1D".'21SN99'));

        self::assertResponseIsSuccessful();
        self::assertSame(['pack', 'LOT-7', '2027-05-31', 'SN99'], [$this->json()['role'], $this->json()['lot'], $this->json()['useBy'], $this->json()['serial']]);
        $this->getJson($this->path('(01)13017620422000(10)B2'));
        self::assertSame('B2', $this->json()['lot']);
    }

    public function testACodeNobodyHoldsAndAnotherCompanysCodeAreNotFound(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->aProductWith([['role' => 'unit', 'code' => '3017620422003', 'quantity' => 1]]);

        $this->getJson($this->path('036000291452'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        // Part of a code is not the code.
        $this->getJson($this->path('301762042'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $other = $this->createCompany('Globex');
        $this->getJson('/api/companies/'.$other->getId()->toRfc4122().'/product-scan?code=3017620422003');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSomebodyWhoCannotReadProductsIsAnsweredAsAStranger(): void
    {
        $this->signedIn(['company.read']);

        $this->getJson($this->path('3017620422003'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param list<array<string, mixed>> $codes */
    private function aProductWith(array $codes): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $base = '/api/companies/'.$this->company->getId()->toRfc4122().'/products';
        $this->postJson($base, [
            'reference' => 'ART-001', 'name' => 'Portable', 'description' => null, 'kind' => 'goods',
            'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => '10', 'costPrice' => null, 'categoryId' => null,
            'defaultTaxComponentIds' => [], 'customFields' => [], 'isActive' => true,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->sendJson('PUT', "$base/$id/barcodes", ['barcodes' => $codes]);
        self::assertResponseIsSuccessful();

        return $id;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, $permissions, 'clerk');
        $this->login('clerk@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(string $code): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/product-scan?code='.rawurlencode($code);
    }
}

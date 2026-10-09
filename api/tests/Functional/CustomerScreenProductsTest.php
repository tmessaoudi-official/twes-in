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
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the customer screen may show of a product: its name, its final tax-included price, our own reference and
 * barcode. The allow-list is the answer's own shape, so a screen cannot draw what the API never sent.
 */
final class CustomerScreenProductsTest extends ApiTestCase
{
    private Company $company;
    private string $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Sotumag'), new \DateTimeImmutable());
        $this->em()->persist($vendor);
        $this->em()->flush();
        $this->vendorId = $vendor->getId()->toRfc4122();
    }

    public function testASearchAnswersTheNameTheFinalPriceTheReferenceAndTheBarcodeAndNothingElse(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);
        $id = $this->aProduct('ART-001', 'Portable', '100', '55', ['FODEC', 'TVA19'], [['role' => 'unit', 'code' => '3017620422003', 'quantity' => 1], ['role' => 'supplier', 'code' => 'SUP-9', 'quantity' => 1, 'supplierId' => $this->vendorId]]);

        $this->getJson($this->path('Portable'));

        self::assertResponseIsSuccessful();
        self::assertSame(['items'], array_keys($this->json()));
        self::assertSame(
            [['id' => $id, 'name' => 'Portable', 'reference' => 'ART-001', 'barcode' => '3017620422003', 'finalPrice' => '120.190', 'photoId' => null]],
            $this->json()['items'],
            'a person who may read costs still sees none here: the screen is for a customer',
        );
    }

    public function testAScanOfABarcodeFindsItsProductAndASupplierCodeIsNeverShown(): void
    {
        $this->signedIn(['product.read', 'product.write', 'product.cost.read']);
        $this->aProduct('ART-001', 'Portable', '100', null, [], [['role' => 'supplier', 'code' => 'SUP-9', 'quantity' => 1, 'supplierId' => $this->vendorId]]);
        $this->aProduct('ART-002', 'Clavier', '10', null, [], [['role' => 'unit', 'code' => '3017620422003', 'quantity' => 1]]);

        $this->getJson($this->path('3017620422003'));
        self::assertSame(['ART-002'], array_column($this->arrayAt($this->json(), 'items'), 'reference'));

        $this->getJson($this->path('ART-001'));
        self::assertSame([null], array_column($this->arrayAt($this->json(), 'items'), 'barcode'), 'a product holding only its supplier\'s code has no barcode of ours');
    }

    public function testARetiredProductAndAnotherCompanysProductAreNotOffered(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->aProduct('ART-001', 'Portable', '10', null, [], [], false);

        $this->getJson($this->path('Portable'));
        self::assertSame([], $this->json()['items']);

        $other = $this->createCompany('Globex');
        $this->getJson('/api/companies/'.$other->getId()->toRfc4122().'/customer-screen/products?q=Portable');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSomebodyWhoCannotReadProductsIsAnsweredAsAStranger(): void
    {
        $this->signedIn(['company.read']);

        $this->getJson($this->path('Portable'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @param list<string>               $taxCodes
     * @param list<array<string, mixed>> $codes
     */
    private function aProduct(string $reference, string $name, string $price, ?string $cost, array $taxCodes, array $codes, bool $active = true): string
    {
        $taxes = static::getContainer()->get(TaxComponentRepository::class);
        $taxIds = array_map(function (string $code) use ($taxes): string {
            $tax = $taxes->ofCodeInCompany($code, $this->company->getId());
            self::assertNotNull($tax);

            return $tax->getId()->toRfc4122();
        }, $taxCodes);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $base = '/api/companies/'.$this->company->getId()->toRfc4122().'/products';
        $this->postJson($base, [
            'reference' => $reference, 'name' => $name, 'description' => null, 'kind' => 'goods',
            'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => $price, 'costPrice' => $cost, 'categoryId' => null,
            'defaultTaxComponentIds' => $taxIds, 'customFields' => [], 'isActive' => $active,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        if ([] !== $codes) {
            $this->sendJson('PUT', "$base/$id/barcodes", ['barcodes' => $codes]);
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

    private function path(string $words): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/customer-screen/products?q='.rawurlencode($words);
    }
}

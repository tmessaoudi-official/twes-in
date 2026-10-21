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
use App\Module\Products\Domain\ValueObject\Barcode;
use App\Module\Products\Domain\ValueObject\BarcodeLine;
use App\Module\Products\Domain\ValueObject\BarcodeRole;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who a product is bought from, read by whoever reads the product and named by whoever writes it, on its own form as
 * a file names it: exactly the vendors sent, a list left out keeping them, and a refusal under the field that names it.
 */
final class ProductVendorsTest extends ApiTestCase
{
    private Company $company;
    private Vendor $coded;
    private Vendor $other;
    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $now = new \DateTimeImmutable();
        $this->coded = Vendor::create($this->company, 'FRN-0002', new VendorProfile('Sotumag'), $now);
        $this->other = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Comptoir Sfax'), $now);
        $this->em()->persist($this->coded);
        $this->em()->persist($this->other);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $product = Product::create($this->company, 'VIS-6X40', new ProductDetails('Vis 6x40', null, ProductKind::Goods, '0.45', '0.22'), $unit, null, [], $now);
        $product->replaceBarcodes([new BarcodeLine(BarcodeRole::Supplier, new Barcode('F-VIS-6X40'), 1, $this->coded)], $now);
        $this->em()->persist($product);
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();
    }

    public function testWhoeverReadsTheProductReadsWhoItIsBoughtFrom(): void
    {
        $this->signedIn(['product.read']);

        $this->getJson($this->path());

        self::assertResponseIsSuccessful();
        self::assertSame([$this->id($this->coded)], $this->json()['vendorIds']);
        self::assertSame([['id' => $this->id($this->coded), 'number' => 'FRN-0002', 'name' => 'Sotumag']], $this->json()['vendors']);
    }

    public function testTheVendorsSentAreExactlyTheProductsInTheOrderOfTheirNumbers(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->sendJson('PUT', $this->path(), $this->body(['vendorIds' => [$this->id($this->coded), $this->id($this->other)]]));

        self::assertResponseIsSuccessful();
        self::assertSame([$this->id($this->other), $this->id($this->coded)], $this->json()['vendorIds']);
        self::assertSame(['FRN-0001', 'FRN-0002'], $this->storedNumbers());
    }

    public function testAFormThatSendsNoListKeepsThem(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->sendJson('PUT', $this->path(), $this->body(['name' => 'Vis 6x40 zinguée']));

        self::assertResponseIsSuccessful();
        self::assertSame(['FRN-0002'], $this->storedNumbers());
    }

    public function testAVendorStillPrintingACodeForItStaysAndTheRefusalSaysWhichOne(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->sendJson('PUT', $this->path(), $this->body(['vendorIds' => [$this->id($this->other)]]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'vendorIds', 'reason' => 'vendor_has_code', 'params' => ['number' => 'FRN-0002']]], $this->refusals());
        self::assertSame(['FRN-0002'], $this->storedNumbers());
    }

    public function testAnIdNoVendorOfTheCompanyHasIsRefusedUnderItsPlaceInTheList(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $stranger = $this->createCompany('Ailleurs');
        $theirs = Vendor::create($stranger, 'FRN-0001', new VendorProfile('Le leur'), new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->flush();

        $this->sendJson('PUT', $this->path(), $this->body(['vendorIds' => [$this->id($this->coded), $this->id($theirs)]]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'vendorIds.1', 'reason' => 'unknown_choice', 'params' => []]], $this->refusals());
    }

    public function testAVendorMergedIntoAnotherIsRefusedSoTheOneKeptIsNamed(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->em()->getConnection()->executeStatement('UPDATE vendor SET merged_into_id = ?, is_active = false WHERE id = ?', [$this->id($this->coded), $this->id($this->other)]);

        $this->sendJson('PUT', $this->path(), $this->body(['vendorIds' => [$this->id($this->coded), $this->id($this->other)]]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'vendorIds.1', 'reason' => 'unknown_choice', 'params' => []]], $this->refusals());
    }

    public function testANewProductNamesItsVendorsAsItIsCreated(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->postJson($this->companyPath().'/products', $this->body(['reference' => 'VIS-8X60', 'vendorIds' => [$this->id($this->other)]]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([$this->id($this->other)], $this->json()['vendorIds']);
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
            'version' => $this->storedVersion('product', $this->productId),
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

    /** @return list<string> */
    private function storedNumbers(): array
    {
        $this->em()->clear();
        $product = $this->em()->find(Product::class, $this->productId);
        self::assertInstanceOf(Product::class, $product);

        return array_map(static fn (Vendor $vendor): string => $vendor->getNumber(), $product->getVendors());
    }

    private function id(Vendor $vendor): string
    {
        return $vendor->getId()->toRfc4122();
    }

    private function path(): string
    {
        return $this->companyPath().'/products/'.$this->productId;
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('shop@twes.local', 'password-1234', $this->company, ['company.read', ...$permissions], 'member');
        $this->login('shop@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}

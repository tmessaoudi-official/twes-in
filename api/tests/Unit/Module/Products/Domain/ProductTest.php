<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Domain;

use App\Fiscal\Domain\Unit;
use App\Module\Products\Domain\InvalidProduct;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductCategory;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProductTest extends TestCase
{
    private Company $company;
    private Unit $piece;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->now = new \DateTimeImmutable('2026-09-14 09:00:00');
        $this->piece = Unit::create($this->company, 'C62', 'pièce', 0, 0, $this->now);
    }

    public function testAProductCarriesItsReferenceDetailsUnitCategoryAndTaxes(): void
    {
        $category = ProductCategory::create($this->company, 'Matériel', null, $this->now);
        $tax = Uuid::v7();

        $product = Product::create($this->company, ' ART-001 ', self::details(unitPriceNet: '1250.5', costPrice: '900'), $this->piece, $category, [$tax, $tax], $this->now);

        self::assertSame('ART-001', $product->getReference());
        self::assertSame('Portable 14"', $product->getDetails()->name);
        self::assertSame(ProductKind::Goods, $product->getDetails()->kind);
        self::assertSame('1250.5000', $product->getDetails()->unitPriceNet, 'a unit price is kept at four decimals, NUMERIC(14,4)');
        self::assertSame('900.0000', $product->getDetails()->costPrice);
        self::assertSame($this->piece, $product->getUnit());
        self::assertSame($category, $product->getCategory());
        self::assertSame([$tax->toRfc4122()], $product->getDefaultTaxComponentIds(), 'each tax once');
        self::assertTrue($product->isActive());
    }

    public function testAReferenceHasTheShapeOfADocumentIdentifier(): void
    {
        foreach (['', '-ART', 'ART 001', str_repeat('A', 33)] as $reference) {
            try {
                Product::create($this->company, $reference, self::details(), $this->piece, null, [], $this->now);
                self::fail('refused: '.$reference);
            } catch (InvalidProduct $refused) {
                self::assertSame('reference', $refused->field);
            }
        }
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function refusedDetails(): iterable
    {
        yield 'blank name' => ['name', ['name' => '  ']];
        yield 'long name' => ['name', ['name' => str_repeat('a', ProductDetails::NAME_MAX + 1)]];
        yield 'long description' => ['description', ['description' => str_repeat('a', ProductDetails::DESCRIPTION_MAX + 1)]];
        yield 'negative price' => ['unitPriceNet', ['unitPriceNet' => '-1']];
        yield 'five decimals' => ['unitPriceNet', ['unitPriceNet' => '1.00001']];
        yield 'eleven integer digits' => ['unitPriceNet', ['unitPriceNet' => '12345678901']];
        yield 'not a number' => ['unitPriceNet', ['unitPriceNet' => '12,5']];
        yield 'leading zero' => ['unitPriceNet', ['unitPriceNet' => '012']];
        yield 'exponent' => ['costPrice', ['costPrice' => '1e3']];
        yield 'barcode with a space' => ['barcode', ['barcode' => '3017 620422003']];
        yield 'long barcode' => ['barcode', ['barcode' => str_repeat('1', 65)]];
    }

    /** @param array<string, string> $change */
    #[DataProvider('refusedDetails')]
    public function testRefusesMalformedDetailsNamingTheField(string $field, array $change): void
    {
        try {
            self::details(...$change);
            self::fail('refused: '.$field);
        } catch (InvalidProduct $refused) {
            self::assertSame($field, $refused->field);
        }
    }

    public function testTheLargestPriceAndAnEmptyOptionalFieldAreKept(): void
    {
        $details = self::details(description: '  ', unitPriceNet: '9999999999.9999', costPrice: null, barcode: ' ');

        self::assertSame('9999999999.9999', $details->unitPriceNet);
        self::assertSame('0.0000', self::details(unitPriceNet: '0')->unitPriceNet);
        self::assertNull($details->description);
        self::assertNull($details->costPrice);
        self::assertNull($details->barcode);
    }

    public function testAUnitOrACategoryOfAnotherCompanyIsRefused(): void
    {
        $globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');

        try {
            Product::create($this->company, 'ART-001', self::details(), Unit::create($globex, 'C62', 'pièce', 0, 0, $this->now), null, [], $this->now);
            self::fail('a foreign unit is refused');
        } catch (InvalidProduct $refused) {
            self::assertSame('unitId', $refused->field);
        }
        $this->expectExceptionObject(new InvalidProduct('categoryId', 'A product belongs to a category of its own company.'));
        Product::create($this->company, 'ART-001', self::details(), $this->piece, ProductCategory::create($globex, 'Matériel', null, $this->now), [], $this->now);
    }

    public function testARevisionNamesTheFieldsItChanged(): void
    {
        $product = Product::create($this->company, 'ART-001', self::details(), $this->piece, null, [], $this->now);
        $hour = Unit::create($this->company, 'HUR', 'heure', 2, 1, $this->now);
        $category = ProductCategory::create($this->company, 'Services', null, $this->now);
        $tax = Uuid::v7();

        self::assertSame([], $product->revise('ART-001', self::details(unitPriceNet: '1250.0000'), $this->piece, null, [], true, $this->now));
        self::assertSame(
            ['reference', 'kind', 'unitPriceNet', 'barcode', 'unitId', 'categoryId', 'defaultTaxComponentIds', 'isActive'],
            $product->revise('SRV-001', self::details(kind: ProductKind::Service, unitPriceNet: '80', barcode: '3017620422003'), $hour, $category, [$tax], false, $this->now),
        );
        self::assertSame('SRV-001', $product->getReference());
        self::assertSame($hour, $product->getUnit());
        self::assertFalse($product->isActive());
    }

    public function testCustomFieldRevisionsNameTheKeysThatChanged(): void
    {
        $product = Product::create($this->company, 'ART-001', self::details(), $this->piece, null, [], $this->now);

        self::assertSame(['customFields.colour'], $product->reviseCustomFields(['colour' => 'gris'], $this->now));
        self::assertSame([], $product->reviseCustomFields(['colour' => 'gris'], $this->now));
        self::assertSame(['customFields.colour', 'customFields.warranty'], $product->reviseCustomFields(['warranty' => 24], $this->now));
        self::assertSame(['warranty' => 24], $product->getCustomFields());
    }

    private static function details(
        string $name = 'Portable 14"',
        ?string $description = 'Processeur 8 cœurs',
        ProductKind $kind = ProductKind::Goods,
        string $unitPriceNet = '1250',
        ?string $costPrice = '900',
        ?string $barcode = null,
    ): ProductDetails {
        return new ProductDetails($name, $description, $kind, $unitPriceNet, $costPrice, $barcode);
    }
}

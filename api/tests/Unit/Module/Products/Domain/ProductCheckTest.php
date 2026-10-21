<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Domain;

use App\Fiscal\Domain\Model\Unit;
use App\Module\Products\Domain\Exception\InvalidProduct;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Model\ProductCategory;
use App\Module\Products\Domain\ValueObject\Barcode;
use App\Module\Products\Domain\ValueObject\BarcodeLine;
use App\Module\Products\Domain\ValueObject\BarcodeRole;
use App\Module\Products\Domain\ValueObject\ProductCheckReason as Reason;
use App\Module\Products\Domain\ValueObject\ProductCheckScope;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Products\Infrastructure\ApiPlatform\Resource\ProductResource;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Shared\Domain\ValueObject\CheckMark;
use App\Tenancy\Domain\Model\Company;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * « À vérifier » on a product: what the product lacks, each reason clearing itself once fixed, beside the mark a person
 * sets with a note. What a reason needs to be said is the scope's: the cost only to whoever may read costs, the VAT only
 * where the company charges it, the count only while it keeps stock.
 */
final class ProductCheckTest extends TestCase
{
    private Company $company;
    private Unit $piece;
    private \DateTimeImmutable $now;

    public function testAProductMissingEverythingSaysEachReasonInOrder(): void
    {
        $product = $this->goods('0', null);
        $product->awaitUnitConfirmation();
        $product->awaitCount();

        self::assertSame(
            [Reason::PriceMissing, Reason::VendorMissing, Reason::VatMissing, Reason::CostMissing, Reason::BarcodeMissing, Reason::CategoryMissing, Reason::UnitToConfirm, Reason::ToCount],
            $product->checkReasons(self::everything()),
        );
    }

    public function testTheApiDocumentNamesEveryReason(): void
    {
        self::assertSame(array_column(Reason::cases(), 'value'), ProductResource::CHECK_REASONS);
    }

    public function testACompleteProductSaysNone(): void
    {
        self::assertSame([], $this->complete()->checkReasons(self::everything()));
    }

    public function testTheScopeWithholdsWhatItsReaderOrItsCompanyDoesNotSay(): void
    {
        $product = $this->goods('0', null);
        $product->awaitCount();

        $reasons = $product->checkReasons(new ProductCheckScope(keepsStock: false, chargesVat: false, seesCosts: false));

        self::assertNotContains(Reason::CostMissing, $reasons, 'a cost is said only to whoever may read costs');
        self::assertNotContains(Reason::VatMissing, $reasons, 'no VAT is missing where the company charges none');
        self::assertNotContains(Reason::ToCount, $reasons, 'nothing is counted where no stock is kept');
        self::assertContains(Reason::PriceMissing, $reasons);
    }

    public function testAServiceIsNeitherBoughtNorCountedNorScanned(): void
    {
        $service = Product::create($this->company, 'SRV-1', new ProductDetails('Pose', null, ProductKind::Service, '0'), $this->piece, null, [], $this->now);
        $service->awaitCount();

        self::assertSame([Reason::PriceMissing, Reason::VatMissing, Reason::CategoryMissing], $service->checkReasons(self::everything()));
    }

    public function testAPriceOfNothingMeantIsSilencedOnceUntilThePriceMovesAndComesBack(): void
    {
        $product = $this->complete('0');
        self::assertSame([Reason::PriceMissing], $product->checkReasons(self::everything()));

        self::assertSame(['zeroPriceIntended'], $product->reviseCheck(new CheckMark(false), true, $this->now));
        self::assertSame([], $product->checkReasons(self::everything()));

        $this->reprice($product, '4.5');
        self::assertFalse($product->isZeroPriceIntended(), 'a price that is no longer nothing has no nothing to mean');
        $this->reprice($product, '0');
        self::assertSame([Reason::PriceMissing], $product->checkReasons(self::everything()), 'back to nothing, it asks again');
    }

    public function testNothingMeantOnAPriceThatIsNotNothingIsNotKept(): void
    {
        $product = $this->complete();

        self::assertSame([], $product->reviseCheck(new CheckMark(false), true, $this->now));
        self::assertFalse($product->isZeroPriceIntended());
    }

    public function testTheUnitAndTheCountClearOnlyWhenConfirmedAndCounted(): void
    {
        $product = $this->complete();
        $product->awaitUnitConfirmation();
        $product->awaitCount();
        self::assertSame([Reason::UnitToConfirm, Reason::ToCount], $product->checkReasons(self::everything()));

        $this->reprice($product, '9');
        self::assertSame([Reason::UnitToConfirm, Reason::ToCount], $product->checkReasons(self::everything()), 'another change confirms no unit');

        self::assertTrue($product->confirmUnit($this->now));
        self::assertFalse($product->confirmUnit($this->now), 'confirmed once');
        self::assertTrue($product->counted($this->now));
        self::assertFalse($product->counted($this->now), 'counted once');
        self::assertSame([], $product->checkReasons(self::everything()));
    }

    public function testTheMarkAPersonSetsKeepsItsNoteAndLeavesItWhenTakenOff(): void
    {
        $product = $this->complete();

        self::assertSame(['toCheck', 'checkNote'], $product->reviseCheck(new CheckMark(true, '  prix du fournisseur à relire  '), false, $this->now));
        self::assertEquals(new CheckMark(true, 'prix du fournisseur à relire'), $product->getCheckMark());
        self::assertSame([], $product->reviseCheck(new CheckMark(true, 'prix du fournisseur à relire'), false, $this->now));
        self::assertSame([], $product->checkReasons(self::everything()), 'the mark is a person\'s, not a reason');

        self::assertSame(['toCheck', 'checkNote'], $product->reviseCheck(new CheckMark(false, 'oublié'), false, $this->now));
        self::assertEquals(new CheckMark(false), $product->getCheckMark(), 'a doubt settled leaves no note behind');
    }

    public function testANoteLongerThanItsMaximumIsRefusedAsTheProductsField(): void
    {
        self::assertNotNull(CheckMark::noteRefusal(str_repeat('é', CheckMark::NOTE_MAX + 1)));
        self::assertNull(CheckMark::noteRefusal(str_repeat('é', CheckMark::NOTE_MAX)));

        try {
            $this->complete()->reviseCheck(new CheckMark(true, str_repeat('a', CheckMark::NOTE_MAX + 1)), false, $this->now);
            self::fail('a note past its maximum is refused');
        } catch (InvalidProduct $refused) {
            self::assertSame(['checkNote', 'invalid_text', ['max' => CheckMark::NOTE_MAX]], [$refused->field, $refused->reason, $refused->params]);
        }
    }

    protected function setUp(): void
    {
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->now = new \DateTimeImmutable('2026-10-21 07:00:00');
        $this->piece = Unit::create($this->company, 'C62', 'pièce', 0, 0, $this->now);
    }

    private static function everything(): ProductCheckScope
    {
        return new ProductCheckScope(keepsStock: true, chargesVat: true, seesCosts: true);
    }

    /** @param list<Uuid> $taxes */
    private function goods(string $price, ?string $cost, ?ProductCategory $category = null, array $taxes = []): Product
    {
        return Product::create($this->company, 'ART-001', new ProductDetails('Ampoule', null, ProductKind::Goods, $price, $cost), $this->piece, $category, $taxes, $this->now);
    }

    private function complete(string $price = '18.462'): Product
    {
        $product = $this->goods($price, '12', ProductCategory::create($this->company, 'Éclairage', null, $this->now), [Uuid::v7()]);
        $product->replaceVendors([Vendor::create($this->company, 'F-001', new VendorProfile('Sotupa'), $this->now)], $this->now);
        $product->replaceBarcodes([new BarcodeLine(BarcodeRole::Unit, new Barcode('036000291452'), 1)], $this->now);

        return $product;
    }

    private function reprice(Product $product, string $price): void
    {
        $details = $product->getDetails()->withUnitPriceNet($price);
        $product->revise($product->getReference(), $details, $this->piece, $product->getCategory(), array_map(Uuid::fromString(...), $product->getDefaultTaxComponentIds()), true, $this->now);
    }
}

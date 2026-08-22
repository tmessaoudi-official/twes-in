<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Product;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\PricedBy;
use Twes\Domain\Pricing\ProductPricing;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Product\Product;

/**
 * The catalogue aggregate — its identity, its two strings, and what it delegates.
 *
 * **THE PRICING ARITHMETIC IS NOT RE-TESTED HERE**, deliberately. `ProductPricingTest` owns every F4 rule: which
 * field was typed, that a cost change preserves the rate and moves the price, the zero-cost case. Re-asserting
 * them through this aggregate would test `ProductPricing` twice and `Product` not at all — and would create a
 * second place for those rules to be described, which is the duplication this aggregate exists to avoid. What is
 * here is what `Product` itself decides.
 */
#[CoversClass(Product::class)]
final class ProductTest extends TestCase
{
    private const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testAProductCarriesItsNameItsPricingAndItsVatRate(): void
    {
        $pricing = self::pricing();
        $vat = Rate::fromPercentage('19');

        $product = Product::create(self::ID, 'Café moulu', $pricing, $vat);

        self::assertSame(self::ID, $product->id());
        self::assertSame('Café moulu', $product->name());
        self::assertNull($product->sku(), 'a new product has no SKU until one is given');
        self::assertSame($pricing, $product->pricing());
        self::assertSame($vat, $product->vatRate());
    }

    /**
     * The cost and the authored field are READ THROUGH, never re-derived.
     *
     * `authoredBy()` is the F4 ruling's whole point — the typed field is stored exactly and never recomputed —
     * so a `Product` that lost track of it would silently rebuild a typed price from a rounded rate, which is
     * the millime-deleting defect `pricing-and-documents.plan.md` § F4 records.
     */
    public function testTheCostAndTheAuthoredFieldAreReadThroughToThePricing(): void
    {
        $product = Product::create(self::ID, 'Café moulu', self::pricing(), Rate::fromPercentage('19'));

        self::assertTrue($product->cost()->equals(Money::of('100.000', Currency::of('TND'))));
        self::assertSame(PricedBy::ProfitRate, $product->authoredBy());

        $typed = ProductPricing::fromNetPrice(
            Money::of('100.000', Currency::of('TND')),
            Money::of('130.000', Currency::of('TND')),
        );

        self::assertSame(
            PricedBy::NetPrice,
            $product->withPricing($typed)->authoredBy(),
            'replacing the pricing must carry its authorship with it — that is the field the ruling protects',
        );
    }

    /**
     * **A NEGATIVE VAT RATE IS REFUSED, and without this a product is storable and UNUSABLE.**
     *
     * `Rate` permits negatives and is right to — it also serves as the PROFIT rate, where F4 rules that selling
     * below cost is real and must not be clamped. The same type serving two roles is why the constraint belongs
     * at each USE SITE, which is the argument {@see \Twes\Domain\Document\DocumentLine} already makes for its
     * own VAT rate. `Product` was the SECOND use site and had no guard for one commit: a product at `-19%` saved
     * cleanly, and every line ever created from it would have been refused by `DocumentLine` — the defect
     * surfacing at invoice time, weeks later, on a catalogue entry that looked fine.
     */
    public function testANegativeVatRateIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No jurisdiction has a negative VAT rate/');

        Product::create(self::ID, 'Café moulu', self::pricing(), Rate::fromPercentage('-19'));
    }

    /** A ZERO VAT rate is legitimate — exempt and zero-rated supplies are real — so only NEGATIVE is refused. */
    public function testAZeroVatRateIsAccepted(): void
    {
        $product = Product::create(self::ID, 'Livre', self::pricing(), Rate::zero());

        self::assertTrue($product->vatRate()->isZero());
    }

    /** The guard holds on the mutator too, not only on creation. */
    public function testANegativeVatRateIsRefusedByTheMutatorAsWell(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No jurisdiction has a negative VAT rate/');

        self::product()->withVatRate(Rate::fromPercentage('-1'));
    }

    public function testTheNameIsTrimmed(): void
    {
        self::assertSame('Café moulu', self::product('  Café moulu  ')->name());
    }

    #[DataProvider('unusableNames')]
    public function testAnUnusableNameIsRefused(string $why, string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs a name|characters/');

        self::product($name);

        self::fail($why);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableNames(): iterable
    {
        yield 'empty' => ['an empty name is refused', ''];

        // WHITESPACE-ONLY IS ITS OWN CASE, not a variation of the empty one. `CLAUDE.md` § Gotchas 2026-08-22
        // records `Assert\NotBlank` passing `"   "` at the transport, which made a blank name a 500 rather than
        // a 422 — the domain's refusal is what the edge constraint mirrors, so it has to be provably present.
        yield 'whitespace only' => ['a name of nothing but whitespace is refused', "  \t "];

        yield 'one character too long' => [
            'a name past the ceiling is refused',
            str_repeat('a', Product::MAX_NAME_LENGTH + 1),
        ];
    }

    public function testANameExactlyAtTheCeilingIsAccepted(): void
    {
        $name = str_repeat('a', Product::MAX_NAME_LENGTH);

        self::assertSame($name, self::product($name)->name());
    }

    /**
     * **THE CEILING COUNTS CHARACTERS, NOT BYTES**, which is the difference between an Arabic catalogue that
     * works and one whose names are refused at a third of their length.
     */
    public function testTheNameCeilingCountsCharactersRatherThanBytes(): void
    {
        // Each Arabic letter is two bytes in UTF-8, so this is at the character ceiling and well past it in
        // bytes. `strlen` would refuse it; `mb_strlen` accepts it.
        $name = str_repeat('ب', Product::MAX_NAME_LENGTH);

        self::assertSame(Product::MAX_NAME_LENGTH * 2, \strlen($name), 'the fixture must exceed the byte ceiling');
        self::assertSame($name, self::product($name)->name());
    }

    #[DataProvider('malformedIds')]
    public function testAMalformedIdIsRefused(string $why, string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/canonical lowercase-hyphenated UUID/');

        Product::create($id, 'Café moulu', self::pricing(), Rate::fromPercentage('19'));

        self::fail($why);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedIds(): iterable
    {
        yield 'not a uuid at all' => ['an arbitrary string is refused', 'product-1'];

        // UPPERCASE AND THE BRACED FORM ARE REFUSED RATHER THAN NORMALISED. An id is a key: accepting two
        // spellings means two rows in any store that has not been told they are the same.
        yield 'uppercase' => ['an uppercase spelling is refused', strtoupper(self::ID)];

        yield 'braced' => ['the braced form is refused', '{' . self::ID . '}'];

        yield 'empty' => ['an empty id is refused', ''];
    }

    public function testASkuIsTrimmedAndABlankOneBecomesAbsent(): void
    {
        self::assertSame('CAFE-500G', self::product()->withSku('  CAFE-500G  ')->sku());
        self::assertNull(self::product()->withSku('   ')->sku(), 'a blank SKU is the same as none');
        self::assertNull(self::product()->withSku(null)->sku());
    }

    public function testAnOverlongSkuIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SKU is \d+ characters/');

        self::product()->withSku(str_repeat('x', Product::MAX_SKU_LENGTH + 1));
    }

    public function testASkuExactlyAtTheCeilingIsAccepted(): void
    {
        $sku = str_repeat('x', Product::MAX_SKU_LENGTH);

        self::assertSame($sku, self::product()->withSku($sku)->sku());
    }

    /**
     * **EVERY MUTATOR RETURNS A NEW PRODUCT AND LEAVES THE ORIGINAL ALONE.**
     *
     * Immutability is a correctness requirement in this project rather than a style — `CLAUDE.md` § Architecture
     * makes it the reason the persistence model is a separate set of mutable Doctrine entities. A mutator that
     * modified in place would let a product already snapshotted onto an in-flight document change underneath it.
     */
    public function testEveryMutatorLeavesTheOriginalUntouched(): void
    {
        $original = self::product('Café moulu')->withSku('CAFE-500G');

        $changed = $original
            ->withName('Café en grains')
            ->withSku('CAFE-1KG')
            ->withVatRate(Rate::fromPercentage('7'));

        self::assertSame('Café moulu', $original->name());
        self::assertSame('CAFE-500G', $original->sku());
        // COMPARED BY VALUE, not against a literal `'19'`: `percentage()` returns the CANONICAL 10-decimal
        // string (`19.0000000000`), which `pricing-and-documents.plan.md` § F4 is explicit is not a display
        // format — it is the value three tiers compare as an exact string. Asserting a literal here would pin a
        // formatting choice this test has no opinion about.
        self::assertTrue(
            $original->vatRate()->equals(Rate::fromPercentage('19')),
            'the original keeps its own VAT rate',
        );

        self::assertSame('Café en grains', $changed->name());
        self::assertSame('CAFE-1KG', $changed->sku());
        self::assertNotSame($original, $changed);
    }

    /**
     * **REHYDRATION VALIDATES EXACTLY AS CREATION DOES.**
     *
     * A rehydration path that trusts its input is how a row that should never have been written becomes an
     * aggregate nobody questions. It also has to be LOUD, because `ClientProvider` and its siblings deliberately
     * no longer wrap a lookup in `catch (\InvalidArgumentException)` — a hydration failure is meant to surface as
     * a 500 rather than as a 404 that hides a corrupt row.
     */
    public function testRehydrationRefusesWhatCreationWouldHaveRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs a name/');

        Product::fromPersistedState(self::ID, '   ', 'CAFE-500G', self::pricing(), Rate::fromPercentage('19'));
    }

    public function testRehydrationRestoresTheSkuItWasGiven(): void
    {
        $product = Product::fromPersistedState(
            self::ID,
            'Café moulu',
            'CAFE-500G',
            self::pricing(),
            Rate::fromPercentage('19'),
        );

        self::assertSame('CAFE-500G', $product->sku());
    }

    private static function product(string $name = 'Café moulu'): Product
    {
        return Product::create(self::ID, $name, self::pricing(), Rate::fromPercentage('19'));
    }

    private static function pricing(): ProductPricing
    {
        return ProductPricing::fromProfitRate(
            Money::of('100.000', Currency::of('TND')),
            Rate::fromPercentage('30'),
        );
    }
}

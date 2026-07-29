<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Pricing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Exception\CurrencyMismatch;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Exception\InvalidCost;
use Twes\Domain\Pricing\PriceCalculator;
use Twes\Domain\Pricing\PricedBy;
use Twes\Domain\Pricing\ProductPricing;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\RoundingMode;

/**
 * Direct tests for the authored-field rule.
 *
 * `PricingVectorsTest` drives the cross-tier fixture; this file covers what a fixture cannot: the
 * mutators, the guards, the boundaries, and one defect a certification round found in code that had
 * looked correct — `withCost` checked the OLD cost for zero and not the new one, so correcting a cost to
 * zero deleted the typed price. Every method here exists because a mutation to the class survived the
 * whole suite without it.
 */
#[CoversClass(ProductPricing::class)]
final class ProductPricingTest extends TestCase
{
    private const string TND = 'TND';

    // ---------------------------------------------------------------- the defect a round found

    /**
     * The P0. Correcting a cost to zero must not destroy a typed price.
     *
     * Applying any rate to a zero cost yields zero, so a price-authored product whose cost becomes zero
     * has to keep its typed value — there is nothing to recompute it from. The earlier version derived a
     * rate from the OLD pair and applied it to the new zero cost, silently replacing 150.000 with 0.000.
     */
    public function testCorrectingTheCostToZeroDoesNotDestroyATypedPrice(): void
    {
        $pricing = ProductPricing::fromNetPrice(
            $this->money('100.000'),
            $this->money('150.000'),
        )->withCost($this->money('0.000'), RoundingMode::HalfUp);

        self::assertSame('150.000', $pricing->netPrice(RoundingMode::HalfUp)->amount());
        self::assertSame('0.000', $pricing->cost()->amount());
        self::assertSame(PricedBy::NetPrice, $pricing->authoredBy(), 'Authorship must not transfer here.');
        self::assertNull(
            $pricing->profitRate(RoundingMode::HalfUp),
            'A rate derived against a zero cost is undefined, so it must be null rather than the old value.',
        );
    }

    /**
     * The mirror case, which is NOT a defect and must keep working.
     *
     * A rate the user *typed* is defined even on a zero cost — nothing is being divided — so it is
     * returned as entered, and the price is legitimately zero.
     */
    public function testATypedRateSurvivesAZeroCostAndIsStillExact(): void
    {
        $pricing = ProductPricing::fromProfitRate(
            $this->money('100.000'),
            Rate::fromPercentage('30'),
        )->withCost($this->money('0.000'), RoundingMode::HalfUp);

        self::assertSame('0.000', $pricing->netPrice(RoundingMode::HalfUp)->amount());
        self::assertSame('30.0000000000', $pricing->profitRate(RoundingMode::HalfUp)?->percentage());
    }

    public function testAZeroOldCostKeepsTheTypedPriceAndTheCostStillChanges(): void
    {
        // The `cost` assertion is the point: the fixture asserted the price and the authorship but not the
        // cost, so an implementation that ignored the cost edit outright passed.
        $pricing = ProductPricing::fromNetPrice($this->money('0.000'), $this->money('50.000'))
            ->withCost($this->money('10.000'), RoundingMode::HalfUp);

        self::assertSame('10.000', $pricing->cost()->amount(), 'The cost edit must be applied.');
        self::assertSame('50.000', $pricing->netPrice(RoundingMode::HalfUp)->amount());
    }

    // ---------------------------------------------------------------- the mutators

    /**
     * `withProfitRate` and `withNetPrice` are what the UI calls on every keystroke-committed edit, and
     * neither had a test: `return $this;` survived both. A discarded edit is a wrong price on the next
     * document.
     */
    public function testTypingANewRateReplacesTheAuthoredValue(): void
    {
        $pricing = ProductPricing::fromNetPrice($this->money('100.000'), $this->money('150.000'))
            ->withProfitRate(Rate::fromPercentage('25'));

        self::assertSame(PricedBy::ProfitRate, $pricing->authoredBy());
        self::assertSame('25.0000000000', $pricing->profitRate(RoundingMode::HalfUp)?->percentage());
        self::assertSame('125.000', $pricing->netPrice(RoundingMode::HalfUp)->amount());
        self::assertSame('100.000', $pricing->cost()->amount(), 'The cost must be carried over unchanged.');
    }

    public function testTypingANewPriceReplacesTheAuthoredValue(): void
    {
        $pricing = ProductPricing::fromProfitRate($this->money('100.000'), Rate::fromPercentage('30'))
            ->withNetPrice($this->money('175.000'));

        self::assertSame(PricedBy::NetPrice, $pricing->authoredBy());
        self::assertSame('175.000', $pricing->netPrice(RoundingMode::HalfUp)->amount());
        self::assertSame('75.0000000000', $pricing->profitRate(RoundingMode::HalfUp)?->percentage());
    }

    public function testItIsImmutable(): void
    {
        $original = ProductPricing::fromProfitRate($this->money('100.000'), Rate::fromPercentage('30'));

        $original->withCost($this->money('200.000'), RoundingMode::HalfUp);
        $original->withNetPrice($this->money('999.000'));
        $original->withProfitRate(Rate::fromPercentage('99'));

        self::assertSame('100.000', $original->cost()->amount());
        self::assertSame('130.000', $original->netPrice(RoundingMode::HalfUp)->amount());
    }

    // ---------------------------------------------------------------- guards

    public function testAPriceInAnotherCurrencyIsRefused(): void
    {
        $this->expectException(CurrencyMismatch::class);

        ProductPricing::fromNetPrice($this->money('100.000'), Money::of('100.00', Currency::of('EUR')));
    }

    public function testACostChangeToAnotherCurrencyIsRefused(): void
    {
        $pricing = ProductPricing::fromProfitRate($this->money('100.000'), Rate::fromPercentage('30'));

        $this->expectException(CurrencyMismatch::class);

        $pricing->withCost(Money::of('100.00', Currency::of('EUR')), RoundingMode::HalfUp);
    }

    /**
     * A negative *cost* is refused.
     *
     * RULED here rather than left ambiguous, because a certification round found it accepted and silently
     * producing a negative selling price. `Money` must allow negatives — a credit note is a negative
     * document — but a product's cost below zero is not a commercial state, and a negative *rate* already
     * covers the real case this might be mistaken for: selling below cost.
     */
    public function testANegativeCostIsRefused(): void
    {
        $this->expectException(InvalidCost::class);

        ProductPricing::fromProfitRate($this->money('-100.000'), Rate::fromPercentage('30'));
    }

    public function testANegativeCostIsRefusedOnAPriceAuthoredProductToo(): void
    {
        $this->expectException(InvalidCost::class);

        ProductPricing::fromNetPrice($this->money('-100.000'), $this->money('50.000'));
    }

    public function testACostChangeToANegativeValueIsRefused(): void
    {
        $pricing = ProductPricing::fromProfitRate($this->money('100.000'), Rate::fromPercentage('30'));

        $this->expectException(InvalidCost::class);

        $pricing->withCost($this->money('-1.000'), RoundingMode::HalfUp);
    }

    // ---------------------------------------------------------------- boundaries

    /**
     * Repeated cost changes must not compound rounding.
     *
     * They do not, and the reason is structural rather than lucky: authorship transfers to the rate on the
     * first edit, so every later edit multiplies the same exact rate by a new cost instead of re-deriving
     * a rate from an already-rounded price.
     */
    public function testRepeatedCostChangesDoNotCompoundRounding(): void
    {
        $pricing = ProductPricing::fromNetPrice($this->money('3.000'), $this->money('10.000'));
        $rate = $pricing->profitRate(RoundingMode::HalfUp)?->percentage();

        foreach (['4.000', '5.000', '6.000', '7.000', '3.000'] as $cost) {
            $pricing = $pricing->withCost($this->money($cost), RoundingMode::HalfUp);
            self::assertSame($rate, $pricing->profitRate(RoundingMode::HalfUp)?->percentage());
        }

        // Back at the original cost, the original price returns exactly.
        self::assertSame('10.000', $pricing->netPrice(RoundingMode::HalfUp)->amount());
    }

    /**
     * The documented magnitude boundary, pinned rather than left as prose.
     *
     * Above roughly 1e9 a cost change rebuilds the price from a 12-decimal rate and drifts by a millime — in
     * EITHER direction. A round found `+0.001` as well as `-0.001` (cost 3e9 with a typed price of 50.000
     * gains one), and the positive direction is the one that matters legally, because the customer
     * overpays. Visible even on a change to the *same* cost, which should be a no-op —
     * visible even on a change to the *same* cost, which should be a no-op. `NUMERIC(19,4)` permits 15
     * integer digits, so the type allows amounts this large; the rate's precision is what runs out. This
     * test exists so the boundary is a known, asserted property instead of a surprise.
     */
    public function testTheAuthoredPriceGuaranteeHasAKnownBoundaryAboveOneBillion(): void
    {
        $pricing = ProductPricing::fromNetPrice(
            $this->money('3000000000.000'),
            $this->money('10000000000.000'),
        );

        $sameCost = $pricing->withCost($this->money('3000000000.000'), RoundingMode::HalfUp);

        self::assertSame(
            '9999999999.999',
            $sameCost->netPrice(RoundingMode::HalfUp)->amount(),
            'Documented boundary: above ~1e9 a cost change loses a millime, because the price is rebuilt '
            . 'from a rate rounded to 12 decimals. If this ever returns 10000000000.000, the precision was '
            . 'raised or the rebuild removed — update the docblock rather than deleting this test.',
        );
    }

    public function testBelowTheBoundaryASameCostChangeIsExact(): void
    {
        $pricing = ProductPricing::fromNetPrice(
            $this->money('300000000.000'),
            $this->money('1000000000.000'),
        )->withCost($this->money('300000000.000'), RoundingMode::HalfUp);

        self::assertSame('1000000000.000', $pricing->netPrice(RoundingMode::HalfUp)->amount());
    }

    // ---------------------------------------------------------------- agreement with PriceCalculator

    /**
     * Pins the delegated results against LITERAL expected values, not against `PriceCalculator`.
     *
     * This test used to compare `ProductPricing` with `PriceCalculator` — which was meaningful while both
     * implemented the formulas, and became a tautology the moment `ProductPricing` started delegating:
     * both sides of every assertion then executed the same code, and breaking both formulas at once still
     * passed. A round caught that. Expected values here are computed by hand and stated as literals, so the
     * test fails if the shared implementation is wrong rather than merely if the two disagree.
     *
     * @param non-empty-string $expected
     */
    #[DataProvider('pricingByHand')]
    public function testDelegatedResultsMatchHandComputedValues(
        string $cost,
        ?string $percentage,
        ?string $netPrice,
        string $expected,
    ): void {
        if (null !== $percentage) {
            // net = cost x (1 + rate)
            self::assertSame($expected, ProductPricing::fromProfitRate(
                $this->money($cost),
                Rate::fromPercentage($percentage),
            )->netPrice(RoundingMode::HalfUp)->amount());

            return;
        }

        // rate = (net - cost) / cost
        self::assertSame($expected, ProductPricing::fromNetPrice(
            $this->money($cost),
            $this->money($netPrice ?? self::fail('a case needs one of percentage or netPrice')),
        )->profitRate(RoundingMode::HalfUp)?->percentage());
    }

    /** @return iterable<string, array{string, ?string, ?string, string}> */
    public static function pricingByHand(): iterable
    {
        // 100.000 x 1.30 = 130.000
        yield 'the default markup' => ['100.000', '30', null, '130.000'];
        // 12.345 x 1.30 = 16.0485, an exact tie at the millime, half-up lifts it
        yield 'a tie at the millime' => ['12.345', '30', null, '16.049'];
        // 100.000 x 0.80 = 80.000
        yield 'a negative rate sells below cost' => ['100.000', '-20', null, '80.000'];
        // 0.010 x 1.00 = 0.010
        yield 'zero markup' => ['0.010', '0', null, '0.010'];
        // (140 - 100) / 100 = 0.40
        yield 'rate from a typed price' => ['100.000', null, '140.000', '40.0000000000'];
        // (10 - 3) / 3 = 2.333333333333...
        yield 'a repeating rate' => ['3.000', null, '10.000', '233.3333333333'];
        // (80 - 100) / 100 = -0.20
        yield 'a negative rate from a typed price' => ['100.000', null, '80.000', '-20.0000000000'];
        // (10000.001 - 10000) / 10000 = 0.0000001
        yield 'one millime on ten thousand' => ['10000.000', null, '10000.001', '0.0000100000'];
    }

    private function money(string $amount): Money
    {
        return Money::of($amount, Currency::of(self::TND));
    }

    /**
     * `cost x (1 + rate)` is ONE multiplication, and that is a correctness claim, not a style preference.
     *
     * `PriceCalculator::netFromCost()` carries the comment "one multiplication, not `cost + (cost x rate)`
     * — the two-step form rounds twice and can land a millime away from this", and a review found nothing
     * asserting it. The two forms are provably identical under half-up, which is why a casual test would
     * miss the difference entirely; they diverge under **half-even**, and half-even is the default rounding
     * of most accounting configurations.
     *
     * The witness, at the default 3-decimal currency:
     *
     *     one step:  0.001 x 1.5   = 0.0015  -> half-even -> 0.002   (tie, last digit 1 is odd)
     *     two steps: 0.001 x 0.5   = 0.0005  -> half-even -> 0.000   (tie, last digit 0 is even)
     *                0.001 + 0.000 = 0.001                            <- a millime short
     *
     * So the two-step form loses the entire margin on this line. Multiply that by a document's worth of
     * lines and it is a wrong total on a legal document.
     */
    public function testTheNetPriceIsOneMultiplicationRatherThanCostPlusMargin(): void
    {
        $tnd = Currency::of('TND');
        $cost = Money::of('0.001', $tnd);
        $rate = Rate::fromPercentage('50');

        $oneStep = new PriceCalculator()->netFromCost($cost, $rate, RoundingMode::HalfEven);

        // The two-step form, written out here precisely so the divergence is visible in the test rather
        // than asserted as a bare literal.
        $twoStep = $cost->plus($cost->multipliedBy($rate->fraction(), RoundingMode::HalfEven));

        self::assertSame('0.002', $oneStep->amount(), 'The single multiplication keeps the millime.');
        self::assertSame('0.001', $twoStep->amount(), 'The two-step form rounds twice and loses it.');
        self::assertFalse(
            $oneStep->equals($twoStep),
            'If these agree, this test has stopped witnessing the double-rounding it exists to forbid — '
            . 'choose operands where the two forms genuinely diverge.',
        );

        // And ProductPricing must use the one-step form too, since it delegates here.
        self::assertSame(
            '0.002',
            ProductPricing::fromProfitRate($cost, $rate)->netPrice(RoundingMode::HalfEven)->amount(),
        );
    }

    /**
     * A derived rate too large to be a `Rate` is reported as null, NOT thrown from the accessor.
     *
     * This is the far end of the finding that widened `Rate::MAX_INTEGER_DIGITS` from 3 to 15. Widening
     * fixed the *reachable* case (a one-millime cost with a one-dinar price), but the bound is still a
     * bound, and it is still reachable — just only from the extremes of `Money`'s own range. CLF has four
     * decimals, so the smallest positive amount is 0.0001 and the largest is 999999999999999.9999; their
     * ratio needs nineteen integer digits.
     *
     * The obligation is the same at both ends: `profitRate()` is a READ ACCESSOR on an aggregate that
     * constructed and persisted legally, so it must answer "there is no rate to show" rather than raising.
     * Null already carries that meaning for a zero cost, and the form renders an empty field either way.
     */
    public function testADerivedRateBeyondTheStorableBoundIsNullRatherThanAThrow(): void
    {
        $clf = Currency::of('CLF');
        $cost = Money::of('0.0001', $clf);
        $net = Money::of('999999999999999.9999', $clf);

        // The precondition, asserted so this test cannot quietly stop exercising the bound: the ratio really
        // does exceed what a Rate can hold.
        $fraction = $net->minus($cost)->ratioTo($cost, Rate::FRACTION_SCALE, RoundingMode::HalfUp);
        self::assertFalse(
            Rate::canHoldFraction($fraction),
            'This pair no longer exceeds the bound, so the test proves nothing. Widen the operands.',
        );

        self::assertNull(
            new PriceCalculator()->profitRateFromNet($cost, $net, RoundingMode::HalfUp),
            'An unrepresentable derived rate is reported, not raised.',
        );

        // And through the aggregate, which is where a throw would surface as a 500 on a product page.
        self::assertNull(
            ProductPricing::fromNetPrice($cost, $net)->profitRate(RoundingMode::HalfUp),
        );
    }

    /**
     * An unrepresentable net price is refused when the pair is COMBINED, never raised when the price is READ.
     *
     * The mirror of the `profitRate()` fix from a round earlier, and deliberately the opposite remedy. A
     * *rate* that cannot be derived is legitimately absent, so null is the honest answer. A *price* always
     * exists, so there is nothing to report — the invalid combination must simply not be constructible.
     *
     * These three triples are certification round 5's own reproduction cases. Each constructed, persisted,
     * and then threw on every read of its price; because the product page reads the price, the record could
     * not be repaired through the UI. Every value is far inside `NUMERIC(19,4)`: matching `Money`'s and
     * `Rate`'s bounds individually says nothing about their PRODUCT.
     */
    #[DataProvider('editsWhoseNetPriceWouldNotBeRepresentable')]
    public function testAnUnrepresentableNetPriceIsRefusedAtTheEditNotAtTheRead(
        string $cost,
        string $net,
        string $newCost,
    ): void {
        $tnd = Currency::of('TND');
        $pricing = ProductPricing::fromNetPrice(Money::of($cost, $tnd), Money::of($net, $tnd));

        try {
            $edited = $pricing->withCost(Money::of($newCost, $tnd), RoundingMode::HalfUp);
        } catch (InvalidCost $refused) {
            // The message must name the implied price, or an operator cannot tell which value is at fault.
            self::assertStringContainsString('net price', $refused->getMessage());
            self::assertStringContainsString('check the cost', $refused->getMessage());

            return;
        }

        // If the edit was allowed then the READ must work — that is the whole promise. A throw here is the
        // defect; a clean read means this case no longer exceeds the bound and the operands need widening.
        self::fail(\sprintf(
            'The edit was allowed and the price read back as %s, so this case no longer exercises the '
            . 'bound. Widen the operands.',
            $edited->netPrice(RoundingMode::HalfUp)->amount(),
        ));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function editsWhoseNetPriceWouldNotBeRepresentable(): iterable
    {
        yield 'millime cost corrected upward' => ['0.001', '100000.000', '10000000.000'];
        yield 'millime cost, million price' => ['0.001', '1000000.000', '1000000.000'];
        yield 'unit cost, billion correction' => ['1.000', '1000000.000', '1000000000.000'];
    }

    /**
     * The ordinary large-but-fine case is still accepted, so the guard is a bound and not a wall.
     *
     * A 1000 % margin on a 1 000 000 TND cost is 11 000 000 — eight integer digits, comfortably storable.
     */
    public function testALargeButRepresentableNetPriceIsStillAccepted(): void
    {
        $tnd = Currency::of('TND');

        $pricing = ProductPricing::fromProfitRate(
            Money::of('1000000.000', $tnd),
            Rate::fromPercentage('1000'),
        );

        self::assertSame('11000000.000', $pricing->netPrice(RoundingMode::HalfUp)->amount());
    }
}

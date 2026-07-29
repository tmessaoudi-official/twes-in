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
use Twes\Domain\Pricing\Exception\InvalidRate;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\RoundingMode;

/**
 * `Rate` had no test file. A certification round showed what that cost: switching either factory from
 * `RoundingMode::Unnecessary` to `HalfUp` survived the entire suite — turning `InvalidRate::tooPrecise`,
 * whose own message says a rate is "refused rather than rounded, because a silently rounded rate produces
 * a price that does not match the rate shown beside it", into exactly that silent rounding.
 */
#[CoversClass(Rate::class)]
final class RateTest extends TestCase
{
    #[DataProvider('percentages')]
    public function testPercentageRoundTripsExactly(string $input, string $expectedPercentage, string $expectedFraction): void
    {
        $rate = Rate::fromPercentage($input);

        self::assertSame($expectedPercentage, $rate->percentage());
        self::assertSame($expectedFraction, $rate->fraction());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function percentages(): iterable
    {
        yield 'the default profit rate' => ['30', '30.0000000000', '0.300000000000'];
        yield 'a VAT rate' => ['19', '19.0000000000', '0.190000000000'];
        yield 'zero' => ['0', '0.0000000000', '0.000000000000'];
        yield 'negative — selling below cost' => ['-20', '-20.0000000000', '-0.200000000000'];
        yield 'a repeating input at full precision' => ['33.3333333333', '33.3333333333', '0.333333333333'];
        yield 'the smallest representable rate' => ['0.0000000001', '0.0000000001', '0.000000000001'];
        yield 'the smallest negative' => ['-0.0000000001', '-0.0000000001', '-0.000000000001'];
        yield 'the millime-on-ten-thousand case' => ['0.0000100000', '0.0000100000', '0.000000100000'];
        yield 'above one hundred percent' => ['233.3333333333', '233.3333333333', '2.333333333333'];
    }

    /**
     * The mutants this kills: `Unnecessary → HalfUp` in either factory. Without these two cases the
     * documented "refused rather than rounded" contract is enforced by nothing.
     */
    public function testAPercentageTooPreciseToStoreIsRefusedRatherThanRounded(): void
    {
        $this->expectException(InvalidRate::class);
        $this->expectExceptionMessageMatches('/refused rather than rounded/');

        Rate::fromPercentage('0.00000000001');
    }

    public function testAFractionTooPreciseToStoreIsRefusedRatherThanRounded(): void
    {
        $this->expectException(InvalidRate::class);

        Rate::fromFraction('0.0000000000001');
    }

    #[DataProvider('malformed')]
    public function testMalformedInputIsRefused(string $value): void
    {
        $this->expectException(InvalidRate::class);
        $this->expectExceptionMessageMatches('/not a plain decimal/');

        Rate::fromPercentage($value);
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'percent sign' => ['30%'];
        yield 'leading plus' => ['+30'];
        yield 'exponent' => ['3e1'];
        yield 'comma' => ['30,5'];
        yield 'space' => [' 30'];
    }

    /**
     * The rounding mode on the division that derives every profit rate.
     *
     * It was asserted by nothing: mutants discarding the mode entirely survived all 209 unit tests,
     * because no test input rounded *up* and none was an exact tie. The tie case is the serious one —
     * under a half-even mutant it reproduces the precise F4 defect that `authored_by` and the
     * 12-decimal rate were introduced to eliminate: a product sold a millime above cost displaying
     * 0.0000000000 % and losing the millime on the next cost edit.
     */
    public function testTheDerivedRateRoundsUpWhenTheThirteenthDecimalDemandsIt(): void
    {
        $calculator = new \Twes\Domain\Pricing\PriceCalculator();
        $tnd = \Twes\Domain\Money\Currency::of('TND');

        // 8/3 = 2.666666666666_6…, so the 13th fraction decimal is 6 and half-up must lift the 12th.
        $rate = $calculator->profitRateFromNet(
            \Twes\Domain\Money\Money::of('3.000', $tnd),
            \Twes\Domain\Money\Money::of('11.000', $tnd),
            RoundingMode::HalfUp,
        );

        self::assertSame('266.6666666667', $rate?->percentage(), 'A Down mutant returns …6666.');
    }

    public function testTheDerivedRateResolvesAnExactTieByTheGivenMode(): void
    {
        $calculator = new \Twes\Domain\Pricing\PriceCalculator();
        $tnd = \Twes\Domain\Money\Currency::of('TND');
        $cost = \Twes\Domain\Money\Money::of('2000000000.000', $tnd);
        $net = \Twes\Domain\Money\Money::of('2000000000.001', $tnd);

        // 0.001 / 2e9 = 5e-13 — an exact tie at the 13th fraction decimal, so the mode decides alone.
        self::assertSame(
            '0.0000000001',
            $calculator->profitRateFromNet($cost, $net, RoundingMode::HalfUp)?->percentage(),
            'Half-up must lift the tie. A half-even mutant returns 0.0000000000 and deletes the millime.',
        );
        self::assertSame(
            '0.0000000000',
            $calculator->profitRateFromNet($cost, $net, RoundingMode::HalfDown)?->percentage(),
            'Half-down must drop it — so the mode is demonstrably consulted, not ignored.',
        );
    }

    /**
     * A rate whose magnitude cannot be stored.
     *
     * `profit_rate` is `NUMERIC(15,12)` — twelve fraction decimals leave only THREE integer digits — and
     * `Rate` bounded the scale but never the magnitude. A one-millime cost with a typed price of 1000.000
     * derives a rate of 999999, which `withCost` then makes the authored value, so it is what Wave 1 must
     * persist: `ERROR: numeric field overflow`. `Money` has had exactly this guard since round 1.
     */
    public function testARateTooLargeForItsColumnIsRefused(): void
    {
        $this->expectException(InvalidRate::class);
        $this->expectExceptionMessageMatches('/NUMERIC\\(15,12\\)/');

        Rate::fromFraction('1000');
    }

    public function testTheLargestStorableRateIsAccepted(): void
    {
        self::assertSame('99999.9999999999', Rate::fromFraction('999.999999999999')->percentage());
    }

    public function testAMalformedFractionIsRefused(): void
    {
        // fromFraction had no malformed-input coverage at all: removing its guard let '' become 0%.
        $this->expectException(InvalidRate::class);
        $this->expectExceptionMessageMatches('/not a plain decimal/');

        Rate::fromFraction('');
    }

    public function testFromFractionAndFromPercentageAgree(): void
    {
        self::assertTrue(Rate::fromFraction('0.30')->equals(Rate::fromPercentage('30')));
        self::assertTrue(Rate::fromFraction('-0.20')->equals(Rate::fromPercentage('-20')));
    }

    public function testZero(): void
    {
        $zero = Rate::zero();

        self::assertTrue($zero->isZero());
        self::assertFalse($zero->isNegative());
        self::assertSame('0.0000000000', $zero->percentage());
        self::assertTrue($zero->equals(Rate::fromPercentage('0')));
    }

    public function testSignPredicates(): void
    {
        self::assertTrue(Rate::fromPercentage('-0.0000000001')->isNegative());
        self::assertFalse(Rate::fromPercentage('0.0000000001')->isNegative());
        self::assertFalse(Rate::fromPercentage('30')->isZero());
        self::assertFalse(Rate::fromPercentage('-20')->isZero());
    }

    public function testEqualityDistinguishesAdjacentRates(): void
    {
        // At the smallest representable step, so a comparison that silently truncates would pass wrongly.
        self::assertFalse(
            Rate::fromPercentage('30')->equals(Rate::fromPercentage('30.0000000001')),
        );
    }

    #[DataProvider('multipliers')]
    public function testMarkupMultiplier(string $percentage, string $expected): void
    {
        self::assertSame($expected, Rate::fromPercentage($percentage)->markupMultiplier());
    }

    /** @return iterable<string, array{string, string}> */
    public static function multipliers(): iterable
    {
        yield '30%' => ['30', '1.300000000000'];
        yield '0%' => ['0', '1.000000000000'];
        yield '-20%' => ['-20', '0.800000000000'];
        yield '-100% — free' => ['-100', '0.000000000000'];
        yield 'the smallest step' => ['0.0000000001', '1.000000000001'];
    }

    /**
     * The relationship the class documents as its reason for choosing these two numbers: the percentage
     * scale is exactly the fraction scale minus two, so converting between the forms shifts the decimal
     * point and never rounds. If someone changes one constant without the other, this fails.
     */
    public function testThePercentageScaleIsExactlyTwoLessThanTheFractionScale(): void
    {
        self::assertSame(Rate::FRACTION_SCALE - 2, Rate::PERCENTAGE_SCALE);
        self::assertSame(12, Rate::FRACTION_SCALE);
    }
}

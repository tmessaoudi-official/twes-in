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

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\PriceCalculator;
use Twes\Domain\Pricing\ProductPricing;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\Decimal;
use Twes\Domain\Shared\RoundingMode;

/**
 * ONE property, across EVERY entry point that takes a `RoundingMode`: the caller's mode reaches the
 * arithmetic.
 *
 * **Why this file exists rather than a case in each class's own test.** A certification round replaced the
 * `$mode` argument with a hard-coded `RoundingMode::HalfUp` at *six of nine* mode-taking entry points — all
 * six at once — and the suite reported `OK (251 tests, 1247 assertions)` while eight probe values came back
 * wrong. Every class had thorough tests for *what* it computes; nothing tested that the *policy* it was
 * handed was the policy it used. That is a cross-cutting property, and testing it per class is exactly how
 * it went missing: `ProductPricing`'s tests called `PriceCalculator` directly, so they never proved that
 * `ProductPricing` forwarded anything.
 *
 * Rounding policy belongs to the company issuing the document — `CLAUDE.md` § "Architecture" makes it a
 * parameter for that reason — so an ignored mode is not a rounding curiosity: it silently overrides a
 * configured accounting policy, and the resulting number goes onto a legal document.
 *
 * Each entry point is asserted twice, because one assertion is not enough:
 *
 *  1. **Divergence** — two modes must give two different results. A hard-coded mode fails this.
 *  2. **`Unnecessary` refuses** — the mode that must never round quietly. A hard-coded mode turns this
 *     into a silent rounding, inverting the one guarantee `Unnecessary` exists to provide, and divergence
 *     alone would not catch a mutant that hard-coded `Unnecessary`'s *branch* away.
 *
 * `#[CoversNothing]`: the subject is a property of eleven collaborating units, not one class.
 */
#[CoversNothing]
final class RoundingModeIsForwardedTest extends TestCase
{
    /**
     * @param callable(RoundingMode): string $compute the entry point, rendered as a canonical string
     */
    #[DataProvider('entryPointsTakingARoundingMode')]
    public function testTheCallersModeReachesTheArithmetic(
        callable $compute,
        RoundingMode $first,
        string $expectedFirst,
        RoundingMode $second,
        string $expectedSecond,
    ): void {
        // Exact expected values, not merely "the two differ". A mutant that swapped one mode for another
        // would still produce two different answers; only the literals pin which answer belongs to which.
        self::assertSame($expectedFirst, $compute($first), 'under ' . $first->name);
        self::assertSame($expectedSecond, $compute($second), 'under ' . $second->name);

        self::assertNotSame(
            $expectedFirst,
            $expectedSecond,
            'This case cannot detect a discarded mode: both modes expect the same result. Choose operands '
            . 'where the two modes genuinely diverge.',
        );
    }

    /**
     * @param callable(RoundingMode): mixed $compute
     */
    #[DataProvider('entryPointsThatMustRefuseAnUnnecessaryRounding')]
    public function testUnnecessaryIsNotSilentlySubstituted(callable $compute): void
    {
        // Null or a throw, depending on the layer's contract — Decimal reports, Money and the pricing
        // classes raise. What matters is that the result is NOT a quietly rounded number, which is what a
        // hard-coded mode produces.
        try {
            $result = $compute(RoundingMode::Unnecessary);
        } catch (\Throwable) {
            self::assertTrue(true, 'Refused by exception, which satisfies the contract.');

            return;
        }

        self::assertNull(
            $result,
            'RoundingMode::Unnecessary must refuse rather than round. A value came back instead, so the '
            . 'mode was discarded and replaced with one that rounds.',
        );
    }

    /** @return iterable<string, array{callable, RoundingMode, string, RoundingMode, string}> */
    public static function entryPointsTakingARoundingMode(): iterable
    {
        $tnd = Currency::of('TND');

        // ---- Decimal: the primitive every layer above eventually reaches.
        yield 'Decimal::divide' => [
            static fn(RoundingMode $m): string => (string) Decimal::divide('1.000', '3', 3, $m),
            RoundingMode::HalfUp, '0.333',
            RoundingMode::Up, '0.334',
        ];
        yield 'Decimal::rescale' => [
            static fn(RoundingMode $m): string => (string) Decimal::rescale('0.0015', 3, $m),
            RoundingMode::HalfUp, '0.002',
            RoundingMode::HalfDown, '0.001',
        ];

        // ---- Money.
        yield 'Money::multipliedBy' => [
            static fn(RoundingMode $m): string => Money::of('0.001', $tnd)->multipliedBy('1.5', $m)->amount(),
            RoundingMode::HalfUp, '0.002',
            RoundingMode::HalfDown, '0.001',
        ];
        yield 'Money::dividedBy' => [
            static fn(RoundingMode $m): string => Money::of('1.000', $tnd)->dividedBy('3', $m)->amount(),
            RoundingMode::HalfUp, '0.333',
            RoundingMode::Up, '0.334',
        ];
        yield 'Money::ratioTo' => [
            static fn(RoundingMode $m): string => Money::of('2.000', $tnd)
                ->ratioTo(Money::of('3.000', $tnd), 12, $m),
            RoundingMode::HalfUp, '0.666666666667',
            RoundingMode::Down, '0.666666666666',
        ];

        // ---- PriceCalculator: the four formulas.
        yield 'PriceCalculator::netFromCost' => [
            static fn(RoundingMode $m): string => new PriceCalculator()
                ->netFromCost(Money::of('0.001', $tnd), Rate::fromPercentage('50'), $m)
                ->amount(),
            RoundingMode::HalfUp, '0.002',
            RoundingMode::HalfDown, '0.001',
        ];
        yield 'PriceCalculator::profitRateFromNet' => [
            static fn(RoundingMode $m): string => (string) new PriceCalculator()
                ->profitRateFromNet(Money::of('3.000', $tnd), Money::of('5.000', $tnd), $m)
                ?->fraction(),
            RoundingMode::HalfUp, '0.666666666667',
            RoundingMode::Down, '0.666666666666',
        ];
        yield 'PriceCalculator::vat' => [
            static fn(RoundingMode $m): string => new PriceCalculator()
                ->vat(Money::of('0.013', $tnd), Rate::fromPercentage('19'), $m)
                ->amount(),
            RoundingMode::HalfUp, '0.002',
            RoundingMode::Up, '0.003',
        ];

        // ---- ProductPricing: the three accessors that delegate. These are the ones a per-class test
        // missed entirely, because ProductPricing's own tests exercised PriceCalculator directly.
        yield 'ProductPricing::netPrice' => [
            static fn(RoundingMode $m): string => ProductPricing::fromProfitRate(
                Money::of('0.001', $tnd),
                Rate::fromPercentage('50'),
            )->netPrice($m)->amount(),
            RoundingMode::HalfUp, '0.002',
            RoundingMode::HalfDown, '0.001',
        ];
        yield 'ProductPricing::profitRate' => [
            static fn(RoundingMode $m): string => (string) ProductPricing::fromNetPrice(
                Money::of('3.000', $tnd),
                Money::of('5.000', $tnd),
            )->profitRate($m)?->fraction(),
            RoundingMode::HalfUp, '0.666666666667',
            RoundingMode::Down, '0.666666666666',
        ];

        // withCost derives the rate that becomes the authored value, so the mode it was given is baked into
        // the returned aggregate for good. Read back with Unnecessary, which cannot itself round: whatever
        // difference shows up was decided by the mode passed to withCost.
        yield 'ProductPricing::withCost' => [
            static fn(RoundingMode $m): string => (string) ProductPricing::fromNetPrice(
                Money::of('3.000', $tnd),
                Money::of('5.000', $tnd),
            )->withCost(Money::of('6.000', $tnd), $m)
                ->profitRate(RoundingMode::Unnecessary)
                ?->fraction(),
            RoundingMode::HalfUp, '0.666666666667',
            RoundingMode::Down, '0.666666666666',
        ];
    }

    /** @return iterable<string, array{callable}> */
    public static function entryPointsThatMustRefuseAnUnnecessaryRounding(): iterable
    {
        $tnd = Currency::of('TND');

        yield 'Decimal::divide' => [
            static fn(RoundingMode $m): ?string => Decimal::divide('1.000', '3', 3, $m),
        ];
        yield 'Decimal::rescale' => [
            static fn(RoundingMode $m): ?string => Decimal::rescale('0.0015', 3, $m),
        ];
        yield 'Money::multipliedBy' => [
            static fn(RoundingMode $m): Money => Money::of('0.001', $tnd)->multipliedBy('1.5', $m),
        ];
        yield 'Money::dividedBy' => [
            static fn(RoundingMode $m): Money => Money::of('1.000', $tnd)->dividedBy('3', $m),
        ];
        yield 'Money::ratioTo' => [
            static fn(RoundingMode $m): string => Money::of('2.000', $tnd)
                ->ratioTo(Money::of('3.000', $tnd), 12, $m),
        ];
        yield 'PriceCalculator::netFromCost' => [
            static fn(RoundingMode $m): Money => new PriceCalculator()
                ->netFromCost(Money::of('0.001', $tnd), Rate::fromPercentage('50'), $m),
        ];
        yield 'PriceCalculator::profitRateFromNet' => [
            static fn(RoundingMode $m): ?Rate => new PriceCalculator()
                ->profitRateFromNet(Money::of('3.000', $tnd), Money::of('5.000', $tnd), $m),
        ];
        yield 'PriceCalculator::vat' => [
            static fn(RoundingMode $m): Money => new PriceCalculator()
                ->vat(Money::of('0.013', $tnd), Rate::fromPercentage('19'), $m),
        ];
        yield 'ProductPricing::netPrice' => [
            static fn(RoundingMode $m): Money => ProductPricing::fromProfitRate(
                Money::of('0.001', $tnd),
                Rate::fromPercentage('50'),
            )->netPrice($m),
        ];
        yield 'ProductPricing::profitRate' => [
            static fn(RoundingMode $m): ?Rate => ProductPricing::fromNetPrice(
                Money::of('3.000', $tnd),
                Money::of('5.000', $tnd),
            )->profitRate($m),
        ];
        yield 'ProductPricing::withCost' => [
            static fn(RoundingMode $m): ProductPricing => ProductPricing::fromNetPrice(
                Money::of('3.000', $tnd),
                Money::of('5.000', $tnd),
            )->withCost(Money::of('6.000', $tnd), $m),
        ];
    }
}

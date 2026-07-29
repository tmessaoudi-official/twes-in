<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Money;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Exception\CurrencyMismatch;
use Twes\Domain\Money\Exception\InvalidMoneyAmount;
use Twes\Domain\Money\Money;
use Twes\Domain\Shared\RoundingMode;

/**
 * The single most important test file in this repository.
 *
 * twes-in's default currency is TND, which has THREE decimal places, so every assertion here that
 * looks like a nitpick about a third decimal is the default case rather than an edge case.
 */
#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    // ---------------------------------------------------------------- construction

    public function testItCarriesTheCurrencysOwnScale(): void
    {
        self::assertSame('0.100', Money::of('0.1', Currency::of('TND'))->amount());
        self::assertSame('0.10', Money::of('0.1', Currency::of('EUR'))->amount());
        self::assertSame('0', Money::of('0', Currency::of('JPY'))->amount());
    }

    public function testTheTunisianStampDutyRepresentsExactly(): void
    {
        // 0.100 TND is 100 millimes. It is a real, legally required charge on every invoice, and it
        // is unrepresentable in a currency assumed to have two decimals.
        $stampDuty = Money::of('0.100', Currency::of('TND'));

        self::assertSame('0.100', $stampDuty->amount());
        self::assertSame('0.300', $stampDuty->plus($stampDuty)->plus($stampDuty)->amount());
    }

    public function testItAcceptsAnIntegerAmount(): void
    {
        self::assertSame('100.000', Money::of(100, Currency::of('TND'))->amount());
        self::assertSame('-100.000', Money::of(-100, Currency::of('TND'))->amount());
    }

    public function testItAcceptsTrailingZeroesBeyondTheCurrencyScaleBecauseTheyLoseNothing(): void
    {
        // 0.1000 and 0.100 are the same number; normalising is not a silent truncation.
        self::assertSame('0.100', Money::of('0.1000', Currency::of('TND'))->amount());
    }

    /**
     * Strict hydration. A value the currency cannot represent is corrupt data, and the only safe
     * response is to refuse it — truncating here would launder a bad number into a legal document.
     */
    public function testItRefusesAnAmountTheCurrencyCannotRepresent(): void
    {
        $this->expectException(InvalidMoneyAmount::class);
        $this->expectExceptionMessage('0.1001');

        Money::of('0.1001', Currency::of('TND'));
    }

    public function testItRefusesAnAmountWithMoreDecimalsThanEuroCanHold(): void
    {
        $this->expectException(InvalidMoneyAmount::class);

        Money::of('1.005', Currency::of('EUR'));
    }

    public function testItRefusesAFloatOutright(): void
    {
        // Accepting a float would make every guarantee in this class a lie: 0.1 is not 0.1.
        //
        // Asserted here for completeness, but note that THIS FILE CANNOT PROVE THE INTERESTING CASE.
        // It declares strict_types, so a float argument never reaches Money at all. The dangerous
        // caller is a weak-mode one, where PHP would coerce 19.99 to 19 — see MoneyWeakModeTest.
        $this->expectException(InvalidMoneyAmount::class);
        $this->expectExceptionMessageMatches('/float/');

        Money::of(0.1, Currency::of('TND'));
    }

    /**
     * TWO decimals beyond the currency's scale, not one.
     *
     * Every other strictness test here used exactly scale+1 (`0.1001` TND, `1.005` EUR), and a mutation
     * narrowing the remainder's working scale in `Decimal::rescale` survived all of them — it returned
     * `0.100` for this amount instead of refusing, laundering an unrepresentable value into a legal
     * document. One digit of margin in a test was the whole gap.
     */
    public function testItRefusesAnAmountTwoDecimalsBeyondTheCurrencyScale(): void
    {
        $this->expectException(InvalidMoneyAmount::class);
        $this->expectExceptionMessage('0.10001');

        Money::of('0.10001', Currency::of('TND'));
    }

    public function testItRefusesAnAmountManyDecimalsBeyondTheCurrencyScale(): void
    {
        $this->expectException(InvalidMoneyAmount::class);

        Money::of('0.1000000001', Currency::of('TND'));
    }

    public function testTheUnusedOperationsAreCorrect(): void
    {
        // negated() is what credit notes and refunds will be built on, and absolute()/isPositive() feed
        // payment-application guards. All three had zero references, and `isPositive()` returning true for
        // zero is the classic form of that bug.
        $negative = Money::of('-2.500', Currency::of('TND'));

        self::assertSame('2.500', $negative->negated()->amount());
        self::assertSame('2.500', $negative->absolute()->amount());
        self::assertSame('2.500', Money::of('2.500', Currency::of('TND'))->absolute()->amount());
        self::assertSame('-2.500', Money::of('2.500', Currency::of('TND'))->negated()->amount());

        self::assertFalse($negative->isPositive());
        self::assertFalse(Money::zero(Currency::of('TND'))->isPositive(), 'Zero is not positive.');
        self::assertTrue(Money::of('0.001', Currency::of('TND'))->isPositive());

        // Negating zero must not produce "-0.000".
        self::assertSame('0.000', Money::zero(Currency::of('TND'))->negated()->amount());
    }

    #[DataProvider('malformedAmounts')]
    public function testItRefusesAMalformedAmount(string $amount): void
    {
        $this->expectException(InvalidMoneyAmount::class);

        Money::of($amount, Currency::of('TND'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedAmounts(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace padded' => [' 100 '];
        yield 'thousands separator' => ['1,000.00'];
        yield 'letters' => ['abc'];
        yield 'two dots' => ['1.2.3'];
        yield 'lone dot' => ['.'];
        yield 'trailing dot' => ['100.'];
        yield 'leading dot' => ['.5'];
        yield 'plus sign' => ['+100.000'];
        yield 'exponent' => ['1e3'];
        yield 'hex' => ['0x10'];
        yield 'double negative' => ['--1'];
    }

    // ---------------------------------------------------------------- exact arithmetic

    public function testAdditionIsExactWhereFloatsAreNot(): void
    {
        // The canonical float failure: 0.1 + 0.2 !== 0.3 in IEEE 754.
        $sum = Money::of('0.1', Currency::of('TND'))->plus(Money::of('0.2', Currency::of('TND')));

        self::assertSame('0.300', $sum->amount());
        self::assertTrue($sum->equals(Money::of('0.3', Currency::of('TND'))));
    }

    public function testSubtractionCanGoNegative(): void
    {
        $result = Money::of('10.000', Currency::of('TND'))->minus(Money::of('12.500', Currency::of('TND')));

        self::assertSame('-2.500', $result->amount());
        self::assertTrue($result->isNegative());
    }

    public function testItRefusesToAddDifferentCurrencies(): void
    {
        $this->expectException(CurrencyMismatch::class);
        $this->expectExceptionMessage('TND');

        Money::of('1.000', Currency::of('TND'))->plus(Money::of('1.00', Currency::of('EUR')));
    }

    /**
     * EVERY cross-currency guard, not just addition's.
     *
     * A review found `ratioTo()` and `compareTo()` guarded but untested: removing either left the suite
     * green, and `100.000 TND` then compared **equal** to `100.00 EUR` — the two amounts share a digit
     * string and differ only in scale and meaning. `compareTo()` is the worse of the two, because it
     * underpins `isLessThan()`/`isGreaterThan()`, which is what payment application will use to decide
     * whether an invoice is settled: a TND invoice would read as paid by a EUR payment of the same numeral.
     *
     * @param callable(Money, Money): mixed $operation
     */
    #[DataProvider('operationsThatMustRefuseAForeignCurrency')]
    public function testEveryCrossCurrencyOperationIsRefused(callable $operation): void
    {
        $this->expectException(CurrencyMismatch::class);

        $operation(
            Money::of('100.000', Currency::of('TND')),
            Money::of('100.00', Currency::of('EUR')),
        );
    }

    /** @return iterable<string, array{callable}> */
    public static function operationsThatMustRefuseAForeignCurrency(): iterable
    {
        yield 'plus' => [static fn(Money $a, Money $b): Money => $a->plus($b)];
        yield 'minus' => [static fn(Money $a, Money $b): Money => $a->minus($b)];
        yield 'ratioTo' => [
            static fn(Money $a, Money $b): string => $a->ratioTo($b, 12, RoundingMode::HalfUp),
        ];
        yield 'compareTo' => [static fn(Money $a, Money $b): int => $a->compareTo($b)];

        // The predicates, because they delegate to compareTo and a future refactor could stop doing so.
        yield 'isLessThan' => [static fn(Money $a, Money $b): bool => $a->isLessThan($b)];
        yield 'isGreaterThan' => [static fn(Money $a, Money $b): bool => $a->isGreaterThan($b)];
    }

    /**
     * `equals()` is the deliberate exception: it answers false rather than throwing.
     *
     * "Is 100 TND the same money as 100 EUR" has a definite answer, and it is no. Asserted so that the
     * asymmetry with `compareTo()` is a documented decision rather than an oversight a later reader
     * "fixes" in either direction.
     */
    public function testEqualsAnswersFalseAcrossCurrenciesRatherThanThrowing(): void
    {
        self::assertFalse(
            Money::of('100.000', Currency::of('TND'))->equals(Money::of('100.00', Currency::of('EUR'))),
        );

        // And the same numeral in the same currency is equal, so the guard above is not just returning
        // false for everything.
        self::assertTrue(
            Money::of('100.000', Currency::of('TND'))->equals(Money::of('100.000', Currency::of('TND'))),
        );
    }

    public function testLargeAmountsDoNotOverflow(): void
    {
        // 64-bit integers top out near 9.22e18; NUMERIC(19,4) does not. Arbitrary precision is the
        // reason this class stores a decimal string rather than scaled integer minor units.
        //
        // 15 integer digits is exactly what NUMERIC(19,4) holds, so this is the largest representable
        // amount rather than an arbitrary big number.
        $largest = Money::of('999999999999999.9999', Currency::of('CLF'));

        self::assertSame('999999999999999.9999', $largest->amount());
        self::assertSame('999999999999998.9999', $largest->minus(Money::of('1', Currency::of('CLF')))->amount());
    }

    /**
     * The range is enforced in the domain, not left to the database.
     *
     * A value that passes domain validation and then fails on INSERT surfaces mid-transaction, far from
     * the code that produced it — which breaks the same "fails loudly rather than being laundered"
     * promise that strict scale checking exists to keep.
     */
    public function testAnAmountTooLargeForTheMoneyColumnIsRefused(): void
    {
        $this->expectException(InvalidMoneyAmount::class);
        $this->expectExceptionMessageMatches('/NUMERIC\\(19,4\\)/');

        Money::of('1000000000000000', Currency::of('CLF'));
    }

    public function testAdditionThatOverflowsTheMoneyColumnIsRefused(): void
    {
        // The guard has to hold on results too, not only on construction: two representable amounts can
        // sum to an unrepresentable one.
        $largest = Money::of('999999999999999.9999', Currency::of('CLF'));

        $this->expectException(InvalidMoneyAmount::class);

        $largest->plus($largest);
    }

    // ---------------------------------------------------------------- rounding

    public function testMultiplicationAppliesVatExactly(): void
    {
        $net = Money::of('130.000', Currency::of('TND'));

        self::assertSame(
            '24.700',
            $net->multipliedBy('0.19', RoundingMode::HalfUp)->amount(),
        );
    }

    #[DataProvider('roundingTies')]
    public function testRoundingModesResolveATieDeliberately(
        string $amount,
        string $factor,
        RoundingMode $mode,
        string $expected,
    ): void {
        $result = Money::of($amount, Currency::of('TND'))->multipliedBy($factor, $mode);

        self::assertSame($expected, $result->amount());
    }

    /** @return iterable<string, array{string, string, RoundingMode, string}> */
    public static function roundingTies(): iterable
    {
        // 0.001 x 0.5 = 0.0005 — an exact tie one digit below TND's scale.
        yield 'half up on a tie' => ['0.001', '0.5', RoundingMode::HalfUp, '0.001'];
        yield 'half down on a tie' => ['0.001', '0.5', RoundingMode::HalfDown, '0.000'];
        yield 'half even on a tie to even' => ['0.001', '0.5', RoundingMode::HalfEven, '0.000'];
        yield 'half even on a tie to odd' => ['0.003', '0.5', RoundingMode::HalfEven, '0.002'];
        yield 'up on a tie' => ['0.001', '0.5', RoundingMode::Up, '0.001'];
        yield 'down on a tie' => ['0.001', '0.5', RoundingMode::Down, '0.000'];
        yield 'ceiling on a tie' => ['0.001', '0.5', RoundingMode::Ceiling, '0.001'];
        yield 'floor on a tie' => ['0.001', '0.5', RoundingMode::Floor, '0.000'];

        // The same tie, negative. Sign is exactly where naive rounding goes wrong.
        yield 'negative half up rounds away from zero' => ['-0.001', '0.5', RoundingMode::HalfUp, '-0.001'];
        yield 'negative half down rounds toward zero' => ['-0.001', '0.5', RoundingMode::HalfDown, '0.000'];
        yield 'negative up' => ['-0.001', '0.5', RoundingMode::Up, '-0.001'];
        yield 'negative down' => ['-0.001', '0.5', RoundingMode::Down, '0.000'];
        yield 'negative ceiling rounds toward positive' => ['-0.001', '0.5', RoundingMode::Ceiling, '0.000'];
        yield 'negative floor rounds toward negative' => ['-0.001', '0.5', RoundingMode::Floor, '-0.001'];
    }

    public function testRoundingIsNotAppliedWhenNothingWouldBeLost(): void
    {
        $result = Money::of('100.000', Currency::of('TND'))->multipliedBy('1.3', RoundingMode::Unnecessary);

        self::assertSame('130.000', $result->amount());
    }

    public function testUnnecessaryRoundingThrowsWhenPrecisionWouldBeLost(): void
    {
        // The mode that turns a silent rounding into a loud failure — for callers that must not lose
        // a millime without saying so.
        $this->expectException(InvalidMoneyAmount::class);

        Money::of('0.001', Currency::of('TND'))->multipliedBy('0.5', RoundingMode::Unnecessary);
    }

    public function testDivisionOfARepeatingDecimalRoundsAtTheCurrencyScale(): void
    {
        $third = Money::of('100.000', Currency::of('TND'))->dividedBy('3', RoundingMode::HalfUp);

        self::assertSame('33.333', $third->amount());
    }

    public function testDivisionByZeroIsRefused(): void
    {
        $this->expectException(\DivisionByZeroError::class);

        Money::of('100.000', Currency::of('TND'))->dividedBy('0', RoundingMode::HalfUp);
    }

    // ---------------------------------------------------------------- comparison

    /**
     * `multipliedBy` and `dividedBy` validate their argument, and nothing asserted it.
     *
     * `multipliedBy` is the path a **line quantity** travels, so under a guard-removal mutant an absent
     * quantity (`''`) silently became a free line and `'+5'` was accepted. Only `Money::of` had coverage.
     */
    public function testAMalformedFactorIsRefused(): void
    {
        $this->expectException(InvalidMoneyAmount::class);
        $this->expectExceptionMessageMatches('/not a plain decimal/');

        Money::of('100.000', Currency::of('TND'))->multipliedBy('', RoundingMode::HalfUp);
    }

    public function testAMalformedFactorWithALeadingPlusIsRefused(): void
    {
        $this->expectException(InvalidMoneyAmount::class);

        Money::of('100.000', Currency::of('TND'))->multipliedBy('+5', RoundingMode::HalfUp);
    }

    public function testAMalformedDivisorIsRefused(): void
    {
        $this->expectException(InvalidMoneyAmount::class);

        Money::of('100.000', Currency::of('TND'))->dividedBy('', RoundingMode::HalfUp);
    }

    /**
     * The equality boundary on the two predicates payment application will be built from.
     *
     * Only the strictly-different direction was asserted, so `<` → `<=` and `>` → `>=` both survived. An
     * inclusive `isGreaterThan` flags an exact-full payment as an overpayment; an inclusive `isLessThan`
     * leaves a fully-paid invoice partial.
     */
    public function testTheComparisonPredicatesAreStrictAtEquality(): void
    {
        $amount = Money::of('100.000', Currency::of('TND'));
        $same = Money::of('100.000', Currency::of('TND'));

        self::assertFalse($amount->isLessThan($same));
        self::assertFalse($amount->isGreaterThan($same));
        self::assertSame(0, $amount->compareTo($same));
    }

    public function testComparison(): void
    {
        $small = Money::of('0.100', Currency::of('TND'));
        $large = Money::of('0.101', Currency::of('TND'));

        self::assertSame(-1, $small->compareTo($large));
        self::assertSame(1, $large->compareTo($small));
        self::assertSame(0, $small->compareTo(Money::of('0.1', Currency::of('TND'))));
        self::assertTrue($small->isLessThan($large));
        self::assertTrue($large->isGreaterThan($small));
        self::assertTrue(Money::zero(Currency::of('TND'))->isZero());
    }

    public function testNegativeZeroIsNormalisedToZero(): void
    {
        // bcmath and Postgres both produce "-0" in places. Two representations of zero would break
        // every equality check downstream.
        $result = Money::of('0.000', Currency::of('TND'))->minus(Money::of('0.000', Currency::of('TND')));

        self::assertSame('0.000', $result->amount());
        self::assertFalse($result->isNegative());
    }

    public function testEqualityRequiresTheSameCurrency(): void
    {
        self::assertFalse(
            Money::of('1', Currency::of('TND'))->equals(Money::of('1', Currency::of('EUR'))),
        );
    }

    public function testItIsImmutable(): void
    {
        $original = Money::of('100.000', Currency::of('TND'));
        $original->plus(Money::of('1.000', Currency::of('TND')));

        self::assertSame('100.000', $original->amount());
    }
}

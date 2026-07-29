<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Shared;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Shared\Decimal;
use Twes\Domain\Shared\RoundingMode;

/**
 * Direct tests for the arithmetic primitive, and specifically for `divide`.
 *
 * **Why this file exists, stated plainly:** it did not, and a certification round proved the gap by
 * mutation testing. `divide` was reached only through one case — `100/3 = 33.333`, which happens to
 * round *down* — so its tie logic and its working-scale computation were never exercised. Two mutants
 * that produce **wrong money** survived the entire 112-test suite:
 *
 *   - deleting the exact tie test → `0.001/2` returns `0.000` instead of `0.001`, and `2.000/3`
 *     returns `0.666` instead of `0.667`;
 *   - narrowing `$working` → `10.000/0.003` returns `3333.334` instead of `3333.333`.
 *
 * Every case below is chosen to kill a specific mutant rather than to raise a coverage percentage.
 * Division is the operation that needs it: multiplication produces an exact product that `rescale`
 * then rounds, so it can never construct a tie the way division can.
 */
#[CoversClass(Decimal::class)]
final class DecimalTest extends TestCase
{
    // ---------------------------------------------------------------- divide: rounding direction

    /**
     * The case that kills "tie test deleted". `2/3 = 0.666…`, whose discarded remainder is above half a
     * unit, so half-up must round the last digit *up*. A mutant that always reports "below half"
     * returns 0.666 and the old suite did not notice.
     */
    public function testDivisionRoundsUpWhenTheRemainderIsAboveHalf(): void
    {
        self::assertSame('0.667', Decimal::divide('2.000', '3', 3, RoundingMode::HalfUp));
        self::assertSame('0.666', Decimal::divide('2.000', '3', 3, RoundingMode::Down));
        self::assertSame('0.667', Decimal::divide('2.000', '3', 3, RoundingMode::Up));
    }

    public function testDivisionRoundsDownWhenTheRemainderIsBelowHalf(): void
    {
        self::assertSame('0.333', Decimal::divide('1.000', '3', 3, RoundingMode::HalfUp));
        self::assertSame('0.334', Decimal::divide('1.000', '3', 3, RoundingMode::Up));
    }

    /**
     * The working-scale mutant. A divisor with its own decimals forces the intermediate
     * `quotient × divisor` product wider than the dividend, and a narrowed working scale truncates it
     * — turning an exact remainder of zero into a spurious one and rounding up.
     */
    public function testDivisionByADecimalDivisorIsExact(): void
    {
        self::assertSame('3333.333', Decimal::divide('10.000', '0.003', 3, RoundingMode::HalfUp));
        self::assertSame('3333.333', Decimal::divide('10.000', '0.003', 3, RoundingMode::Down));
        self::assertSame('14.286', Decimal::divide('1.000', '0.07', 3, RoundingMode::HalfUp));
        self::assertSame('14.285', Decimal::divide('1.000', '0.07', 3, RoundingMode::Down));
    }

    // ---------------------------------------------------------------- divide: exact ties

    /**
     * `0.001 / 2 = 0.0005` — an exact tie one digit below the target scale, reached through division
     * rather than multiplication. This is the case the surviving mutant got wrong, and the case where
     * every rounding mode must be distinguishable.
     *
     * @param non-empty-string $expected
     */
    #[DataProvider('exactTiesThroughDivision')]
    public function testAnExactTieThroughDivisionResolvesByMode(RoundingMode $mode, string $expected): void
    {
        self::assertSame($expected, Decimal::divide('0.001', '2', 3, $mode));
    }

    /** @return iterable<string, array{RoundingMode, string}> */
    public static function exactTiesThroughDivision(): iterable
    {
        yield 'half up' => [RoundingMode::HalfUp, '0.001'];
        yield 'half down' => [RoundingMode::HalfDown, '0.000'];
        yield 'half even to even' => [RoundingMode::HalfEven, '0.000'];
        yield 'up' => [RoundingMode::Up, '0.001'];
        yield 'down' => [RoundingMode::Down, '0.000'];
        yield 'ceiling' => [RoundingMode::Ceiling, '0.001'];
        yield 'floor' => [RoundingMode::Floor, '0.000'];
    }

    public function testHalfEvenThroughDivisionRoundsATieToTheOddNeighbourUpward(): void
    {
        // 0.003 / 2 = 0.0015 — tie, and the truncated last digit (1) is odd, so half-even goes up.
        self::assertSame('0.002', Decimal::divide('0.003', '2', 3, RoundingMode::HalfEven));
    }

    public function testUnnecessaryRoundingRefusesAnInexactDivision(): void
    {
        self::assertNull(Decimal::divide('1.000', '3', 3, RoundingMode::Unnecessary));
    }

    public function testUnnecessaryRoundingAllowsAnExactDivision(): void
    {
        self::assertSame('0.500', Decimal::divide('1.000', '2', 3, RoundingMode::Unnecessary));
    }

    // ---------------------------------------------------------------- divide: signs

    /**
     * Sign is where naive rounding goes wrong, and division has two signs to combine. `Ceiling` and
     * `Floor` are directional in absolute terms, so they must swap behaviour with the sign — a mutant
     * that treats them as "away from zero" and "toward zero" passes every positive test.
     *
     * @param non-empty-string $expected
     */
    #[DataProvider('negativeDivisions')]
    public function testSignIsHandledForEveryMode(
        string $dividend,
        string $divisor,
        RoundingMode $mode,
        string $expected,
    ): void {
        self::assertSame($expected, Decimal::divide($dividend, $divisor, 3, $mode));
    }

    /** @return iterable<string, array{string, string, RoundingMode, string}> */
    public static function negativeDivisions(): iterable
    {
        yield 'negative dividend, half up on a tie' => ['-0.001', '2', RoundingMode::HalfUp, '-0.001'];
        yield 'negative dividend, half down on a tie' => ['-0.001', '2', RoundingMode::HalfDown, '0.000'];
        yield 'negative dividend, ceiling toward positive' => ['-0.001', '2', RoundingMode::Ceiling, '0.000'];
        yield 'negative dividend, floor toward negative' => ['-0.001', '2', RoundingMode::Floor, '-0.001'];
        yield 'negative divisor flips the sign' => ['0.001', '-2', RoundingMode::Floor, '-0.001'];
        yield 'both negative gives a positive' => ['-0.001', '-2', RoundingMode::Floor, '0.000'];
        yield 'negative, above half' => ['-2.000', '3', RoundingMode::HalfUp, '-0.667'];
        yield 'negative ceiling, above half' => ['-2.000', '3', RoundingMode::Ceiling, '-0.666'];
    }

    public function testDivisionByZeroThrows(): void
    {
        $this->expectException(\DivisionByZeroError::class);

        Decimal::divide('1.000', '0.000', 3, RoundingMode::HalfUp);
    }

    // ---------------------------------------------------------------- scale 0, and large values

    public function testDivisionAtScaleZero(): void
    {
        // A zero-decimal currency: the unit is 1 and half a unit is 0.5, so the tie is at .5 exactly.
        self::assertSame('2', Decimal::divide('3', '2', 0, RoundingMode::HalfUp));
        self::assertSame('1', Decimal::divide('3', '2', 0, RoundingMode::HalfDown));
        self::assertSame('2', Decimal::divide('3', '2', 0, RoundingMode::HalfEven));
        self::assertSame('1', Decimal::divide('3', '2', 0, RoundingMode::Down));
    }

    public function testArithmeticBeyondSixtyFourBitIntegers(): void
    {
        // Past 9.22e18, where scaled-integer arithmetic would silently become float.
        self::assertSame(
            '99999999999999999999.0000',
            Decimal::add('99999999999999999998.0000', '1.0000', 4),
        );
        self::assertSame(
            '10000000000000000000.0000',
            Decimal::divide('100000000000000000000.0000', '10', 4, RoundingMode::Unnecessary),
        );
    }

    /**
     * A discarded remainder that extends well beyond one digit past the target scale.
     *
     * The surviving mutant narrowed the remainder's working scale, which silently truncated the discarded
     * part and so mis-decided the rounding. Every existing case discarded exactly one digit, where the
     * mutation is invisible.
     */
    public function testRoundingLooksAtTheWHOLEDiscardedRemainderNotJustItsFirstDigit(): void
    {
        // 0.0005001 is just OVER half a unit at scale 3, so half-down must still round UP.
        self::assertSame('0.001', Decimal::rescale('0.0005001', 3, RoundingMode::HalfDown));
        self::assertSame('0.001', Decimal::rescale('0.0005001', 3, RoundingMode::HalfUp));
        self::assertSame('0.001', Decimal::rescale('0.0005001', 3, RoundingMode::HalfEven));

        // 0.0004999 is just UNDER half, so half-up must round DOWN.
        self::assertSame('0.000', Decimal::rescale('0.0004999', 3, RoundingMode::HalfUp));

        // And an exactly-representable value many digits out must not be treated as a remainder at all.
        self::assertSame('0.100', Decimal::rescale('0.1000000000', 3, RoundingMode::Unnecessary));
        self::assertNull(Decimal::rescale('0.1000000001', 3, RoundingMode::Unnecessary));
    }

    public function testCompareUsesTheWIDESTScaleNotTheNarrowest(): void
    {
        // A comparison at the narrower operand's scale would call these equal.
        self::assertSame(-1, Decimal::compare('0.10', '0.101'));
        self::assertSame(1, Decimal::compare('0.101', '0.10'));
        self::assertSame(0, Decimal::compare('0.10', '0.100'));
        self::assertSame(-1, Decimal::compare('-0.101', '-0.10'));
    }

    /**
     * A dividend whose scale exceeds `$scale + scaleOf($divisor)`.
     *
     * That is the only input class where `divide`'s `max()` term in the working scale becomes
     * load-bearing, and nothing reached it: `RateTest` gets to 11 decimals and the term only matters above
     * 12. Deleting it turned `InvalidRate::tooPrecise` into the silent rounding its own message forbids.
     * Realistic trigger: a rate string carrying float artefacts from the Angular or Flutter tier, like
     * "30.000000000000004", which has 15 decimals.
     */
    public function testDivisionSeesADividendWiderThanTheTargetScalePlusTheDivisor(): void
    {
        // 15 decimals in, target 12, divisor scale 0 — so the remainder lives beyond a narrowed window.
        self::assertNull(
            Decimal::divide('30.000000000000004', '100', 12, RoundingMode::Unnecessary),
            'Not exact at 12 decimals, so Unnecessary must refuse rather than quietly truncate.',
        );
        self::assertSame('0.300000000000', Decimal::divide('30.000000000000004', '100', 12, RoundingMode::Down));
        self::assertNull(Decimal::divide('0.0000000000000001', '1', 12, RoundingMode::Unnecessary));
    }

    #[DataProvider('scales')]
    public function testScaleOf(string $value, int $expected): void
    {
        self::assertSame($expected, Decimal::scaleOf($value));
    }

    /** @return iterable<string, array{string, int}> */
    public static function scales(): iterable
    {
        // A dotless value has scale 0. A mutant inverting the has-a-dot test reported 2 for '100', which
        // no consumer happened to break on — luck of consumer shape, not design.
        yield 'no decimal point' => ['100', 0];
        yield 'single digit, no point' => ['7', 0];
        yield 'three decimals' => ['0.100', 3];
        yield 'twelve decimals' => ['0.300000000000', 12];
        yield 'negative, dotless' => ['-100', 0];
        yield 'negative with decimals' => ['-0.10', 2];
    }

    // ---------------------------------------------------------------- helpers

    #[DataProvider('integerDigitCases')]
    public function testIntegerDigits(string $value, int $expected): void
    {
        self::assertSame($expected, Decimal::integerDigits($value));
    }

    /** @return iterable<string, array{string, int}> */
    public static function integerDigitCases(): iterable
    {
        yield 'zero counts as one digit' => ['0', 1];
        yield 'zero with decimals' => ['0.000', 1];
        yield 'leading zeroes are not significant' => ['000123.45', 3];
        yield 'sign is not a digit' => ['-12345', 5];
        yield 'fifteen digits — the NUMERIC(19,4) limit' => ['999999999999999.9999', 15];
        yield 'sixteen digits — one too many' => ['1000000000000000.0000', 16];
        yield 'a bare fraction' => ['0.5', 1];
    }

    #[DataProvider('wellFormedness')]
    public function testWellFormedness(string $value, bool $expected): void
    {
        self::assertSame($expected, Decimal::isWellFormed($value));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function wellFormedness(): iterable
    {
        yield 'integer' => ['100', true];
        yield 'decimal' => ['100.001', true];
        yield 'negative' => ['-100.001', true];
        yield 'negative zero is well formed' => ['-0', true];
        yield 'empty' => ['', false];
        yield 'leading plus' => ['+1', false];
        yield 'exponent' => ['1e3', false];
        yield 'trailing dot' => ['1.', false];
        yield 'leading dot' => ['.1', false];
        yield 'space' => [' 1', false];
        yield 'thousands separator' => ['1,000', false];
    }

    /**
     * Half-even ties on EVERY odd last digit, not just one.
     *
     * `isLastDigitOdd()` tests membership of ['1','3','5','7','9'] and a review found only ONE of the five
     * exercised: deleting '3', '5', '7' or '9' from that list left the whole suite green, and each deletion
     * silently turns half-even into half-down for a fifth of all ties. The witness for '9' is
     * `0.019 / 2 = 0.0095`, which must round to 0.010 (up, because 9 is odd) and not 0.009.
     *
     * Every expectation here was computed independently in Python's `decimal` module rather than by hand —
     * a hand-computed literal is its own source of error, which this suite has already learned once.
     */
    #[DataProvider('halfEvenTiesOnEachLastDigit')]
    public function testHalfEvenRoundsAwayFromAnOddDigitAndTowardsAnEvenOne(
        string $dividend,
        string $expected,
    ): void {
        self::assertSame(
            $expected,
            Decimal::divide($dividend, '2', 3, RoundingMode::HalfEven),
            'A tie whose truncated last digit is odd must round away from it, for all five odd digits.',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function halfEvenTiesOnEachLastDigit(): iterable
    {
        // dividend / 2 is an exact tie at scale 3; the digit before the trailing 5 is the one under test.
        yield 'odd 1 rounds to 2' => ['0.003', '0.002'];
        yield 'odd 3 rounds to 4' => ['0.007', '0.004'];
        yield 'odd 5 rounds to 6' => ['0.011', '0.006'];
        yield 'odd 7 rounds to 8' => ['0.015', '0.008'];
        yield 'odd 9 rounds to 10' => ['0.019', '0.010'];

        // The even digits must round DOWN. Without these the cases above would also pass on a mutant that
        // always rounded ties up, so half of this provider exists to deny that escape.
        yield 'even 0 stays' => ['0.001', '0.000'];
        yield 'even 2 stays' => ['0.005', '0.002'];
        yield 'even 4 stays' => ['0.009', '0.004'];
        yield 'even 6 stays' => ['0.013', '0.006'];
        yield 'even 8 stays' => ['0.017', '0.008'];
    }

    /**
     * A NEGATIVE divisor, which nothing exercised.
     *
     * `divide()` strips the sign from both operands and reapplies it once. A review deleted the divisor's
     * half of that normalisation and the suite stayed green — while five of seven rounding modes then gave a
     * wrong answer and one produced a fatal `ValueError: bccomp(): Argument #1 is not well-formed`, from the
     * string `'--0.000'` the un-normalised path builds.
     */
    #[DataProvider('divisionsWithNegativeOperands')]
    public function testDivisionNormalisesTheSignOfBothOperands(
        string $dividend,
        string $divisor,
        RoundingMode $mode,
        string $expected,
    ): void {
        self::assertSame($expected, Decimal::divide($dividend, $divisor, 3, $mode));
    }

    /** @return iterable<string, array{string, string, RoundingMode, string}> */
    public static function divisionsWithNegativeOperands(): iterable
    {
        yield 'negative divisor, exact' => ['1.000', '-2', RoundingMode::Unnecessary, '-0.500'];
        yield 'negative dividend, exact' => ['-1.000', '2', RoundingMode::Unnecessary, '-0.500'];
        yield 'both negative, exact' => ['-1.000', '-2', RoundingMode::Unnecessary, '0.500'];

        // Ties, where the mode is applied to the MAGNITUDE and the sign reapplied afterwards. Note the
        // half-even and half-down results are plain '0.000': negative zero has one spelling here.
        yield 'negative divisor, half-up tie' => ['0.001', '-2', RoundingMode::HalfUp, '-0.001'];
        yield 'negative divisor, half-even tie' => ['0.001', '-2', RoundingMode::HalfEven, '0.000'];
        yield 'negative divisor, half-down tie' => ['0.001', '-2', RoundingMode::HalfDown, '0.000'];

        // The DIRECTED modes are defined on the number line, not on the magnitude, so a negative quotient
        // separates them: Floor goes towards -inf and Ceiling towards +inf, which is the pair a
        // magnitude-only implementation gets exactly backwards.
        yield 'negative quotient, floor' => ['0.001', '-3', RoundingMode::Floor, '-0.001'];
        yield 'negative quotient, ceiling' => ['0.001', '-3', RoundingMode::Ceiling, '0.000'];
        yield 'negative quotient, down' => ['0.001', '-3', RoundingMode::Down, '0.000'];
        yield 'negative quotient, up' => ['0.001', '-3', RoundingMode::Up, '-0.001'];

        // Non-tie remainders in both signs, so a mutant that only mishandles ties is not enough.
        yield 'both negative, two thirds' => ['-2.000', '-3', RoundingMode::HalfUp, '0.667'];
        yield 'mixed signs, two thirds' => ['2.000', '-3', RoundingMode::HalfUp, '-0.667'];
    }

    /** Unnecessary must still refuse a rounding when a sign is involved. */
    public function testUnnecessaryRefusesARoundingRegardlessOfSign(): void
    {
        self::assertNull(Decimal::divide('1.000', '-3', 3, RoundingMode::Unnecessary));
        self::assertNull(Decimal::divide('-1.000', '3', 3, RoundingMode::Unnecessary));
    }

    public function testNegativeZeroHasExactlyOneSpelling(): void
    {
        // Every entry point must normalise, or two spellings of zero break every equality downstream.
        self::assertSame('0.000', Decimal::subtract('0.000', '0.000', 3));
        self::assertSame('0.000', Decimal::rescale('-0.0001', 3, RoundingMode::Down));
        self::assertSame('0.000', Decimal::divide('-0.0001', '2', 3, RoundingMode::Down));
        self::assertSame('0.000', Decimal::multiplyExact('-0.000', '3'));
        self::assertFalse(Decimal::isNegative(Decimal::rescale('-0.0001', 3, RoundingMode::Down) ?? 'x'));
    }

    /**
     * `add()` and `subtract()` REFUSE a narrowing scale rather than truncating.
     *
     * `bcadd`/`bcsub` truncate at their scale argument, so these two used to lose digits silently and
     * outside `applyRounding()` — contradicting this class's own promise that all rounding lives in one
     * place. `add('0.1', '0.19', 1)` returned `0.2`, discarding 0.09 with no diagnostic and no
     * `RoundingMode` to consult. Every caller at the time was safe *by construction*, which is a property
     * of those callers rather than of the method.
     *
     * @param string $expectedFragment part of the value the exception must name, so the message is
     *                                 actionable rather than generic
     */
    #[DataProvider('exactOperationsThatWouldHaveToRound')]
    public function testAddAndSubtractRefuseToRoundInsteadOfTruncatingSilently(
        callable $operation,
        string $expectedFragment,
    ): void {
        try {
            $result = $operation();
        } catch (\LogicException $thrown) {
            self::assertStringContainsString($expectedFragment, $thrown->getMessage());
            self::assertStringContainsString('RoundingMode', $thrown->getMessage());

            return;
        }

        self::fail(\sprintf(
            'Expected a refusal, got "%s" — a digit was discarded with no diagnostic.',
            $result,
        ));
    }

    /** @return iterable<string, array{callable, string}> */
    public static function exactOperationsThatWouldHaveToRound(): iterable
    {
        yield 'add loses 0.09' => [static fn(): string => Decimal::add('0.1', '0.19', 1), '0.29'];
        yield 'add loses a millime' => [static fn(): string => Decimal::add('0.005', '0.004', 2), '0.009'];
        yield 'subtract loses a digit' => [
            static fn(): string => Decimal::subtract('1.005', '0.001', 2),
            '1.004',
        ];
    }

    /** The widening and equal-scale cases must still work, or the refusal above is unusable. */
    public function testAddAndSubtractStillAcceptAScaleThatLosesNothing(): void
    {
        self::assertSame('0.290', Decimal::add('0.1', '0.19', 3), 'widening is exact');
        self::assertSame('0.29', Decimal::add('0.10', '0.19', 2), 'equal scale is exact');
        self::assertSame('1.004', Decimal::subtract('1.005', '0.001', 3));

        // Trailing zeros are not "lost digits": 0.20 narrows to 0.2 exactly.
        self::assertSame('0.2', Decimal::add('0.10', '0.10', 1));

        // And the whole-number case both operands share, which is what Money::plus relies on.
        self::assertSame('3.000', Decimal::add('1.000', '2.000', 3));
    }
}

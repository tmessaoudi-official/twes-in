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

    public function testNegativeZeroHasExactlyOneSpelling(): void
    {
        // Every entry point must normalise, or two spellings of zero break every equality downstream.
        self::assertSame('0.000', Decimal::subtract('0.000', '0.000', 3));
        self::assertSame('0.000', Decimal::rescale('-0.0001', 3, RoundingMode::Down));
        self::assertSame('0.000', Decimal::divide('-0.0001', '2', 3, RoundingMode::Down));
        self::assertSame('0.000', Decimal::multiplyExact('-0.000', '3'));
        self::assertFalse(Decimal::isNegative(Decimal::rescale('-0.0001', 3, RoundingMode::Down) ?? 'x'));
    }
}

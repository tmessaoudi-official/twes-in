<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Shared;

/**
 * Exact decimal arithmetic on plain numeric strings. Internal to the money package.
 *
 * **Why not floats:** 0.1 + 0.2 is not 0.3 in IEEE 754, and a wrong amount here is a wrong legal
 * document.
 *
 * **Why not scaled integers:** money columns are `NUMERIC(19,4)`, whose range reaches 10^15 with four
 * decimals. Scaled to ten-thousandths that needs 10^19, and PHP's integers stop at roughly
 * 9.22 x 10^18 — where they silently become floats rather than failing. An intermediate product
 * overflows far sooner than that. Arbitrary-precision strings have no such cliff.
 *
 * **Why bcmath and not a decimal library:** bcmath is a PHP extension, so `Domain/` keeps zero
 * Composer dependencies and stays free of anything that could pull a framework in behind it. bcmath
 * gives exact addition, subtraction, multiplication and comparison; what it does *not* give is
 * rounding — every bcmath function truncates toward zero. All the rounding in this project is
 * therefore in {@see self::applyRounding()}, written once and tested exhaustively, rather than spread
 * across call sites.
 *
 * Every method takes and returns a canonical decimal string: an optional `-`, then digits, then
 * optionally `.` and more digits.
 *
 * @internal to the Domain layer — no Application, Infrastructure or UI code may use it
 */
final class Decimal
{
    private const string PATTERN = '/\A-?\d+(?:\.\d+)?\z/';

    private function __construct() {}

    public static function isWellFormed(string $value): bool
    {
        return 1 === preg_match(self::PATTERN, $value);
    }

    /** Number of digits after the decimal point. */
    public static function scaleOf(string $value): int
    {
        $dot = strpos($value, '.');

        return false === $dot ? 0 : \strlen($value) - $dot - 1;
    }

    public static function add(string $left, string $right, int $scale): string
    {
        return self::normaliseZero(bcadd($left, $right, $scale));
    }

    public static function subtract(string $left, string $right, int $scale): string
    {
        return self::normaliseZero(bcsub($left, $right, $scale));
    }

    /** Exact product: the result scale is the sum of the operand scales, so nothing is discarded. */
    public static function multiplyExact(string $left, string $right): string
    {
        return self::normaliseZero(
            bcmul($left, $right, self::scaleOf($left) + self::scaleOf($right)),
        );
    }

    /** -1, 0 or 1. */
    public static function compare(string $left, string $right): int
    {
        return bccomp($left, $right, max(self::scaleOf($left), self::scaleOf($right)));
    }

    public static function isZero(string $value): bool
    {
        return 0 === bccomp($value, '0', self::scaleOf($value));
    }

    public static function isNegative(string $value): bool
    {
        return -1 === bccomp($value, '0', self::scaleOf($value));
    }

    /**
     * Re-express an exact value at `$scale`, rounding only if digits would be lost.
     *
     * Returns null when `$mode` is {@see RoundingMode::Unnecessary} and rounding would be needed —
     * the caller turns that into a domain exception, so this class raises nothing itself.
     */
    public static function rescale(string $value, int $scale, RoundingMode $mode): ?string
    {
        if (self::scaleOf($value) <= $scale) {
            // Widening only pads zeroes; nothing can be lost.
            return self::normaliseZero(bcadd($value, '0', $scale));
        }

        $negative = self::isNegative($value);
        $magnitude = $negative ? substr($value, 1) : $value;

        $truncated = bcadd($magnitude, '0', $scale);
        $remainder = bcsub($magnitude, $truncated, self::scaleOf($magnitude));

        // Compare the discarded part against half of one unit at the target scale.
        $comparedToHalf = bccomp($remainder, self::halfUnit($scale), self::scaleOf($magnitude) + 1);

        $rounded = self::applyRounding(
            truncated: $truncated,
            hasRemainder: !self::isZero($remainder),
            comparedToHalf: $comparedToHalf,
            negative: $negative,
            scale: $scale,
            mode: $mode,
        );

        if (null === $rounded) {
            return null;
        }

        return self::normaliseZero($negative ? '-' . $rounded : $rounded);
    }

    /**
     * Divide exactly and round at `$scale`.
     *
     * The tie test is exact rather than guard-digit based. The true quotient is
     * `truncated + remainder / divisor`, so the discarded part is exactly half a unit when
     * `2 x remainder == unit x divisor` — an equality between two terminating products, which bcmath
     * can decide outright. Guard digits would only ever *approximate* that boundary.
     *
     * Returns null under {@see RoundingMode::Unnecessary} when the division is not exact.
     *
     * @throws \DivisionByZeroError
     */
    public static function divide(string $dividend, string $divisor, int $scale, RoundingMode $mode): ?string
    {
        if (self::isZero($divisor)) {
            throw new \DivisionByZeroError('Division by zero.');
        }

        $negative = self::isNegative($dividend) !== self::isNegative($divisor);
        $absDividend = self::isNegative($dividend) ? substr($dividend, 1) : $dividend;
        $absDivisor = self::isNegative($divisor) ? substr($divisor, 1) : $divisor;

        // bcdiv truncates, which is exactly the floor of the magnitude we want to start from.
        $truncated = bcdiv($absDividend, $absDivisor, $scale);

        // Exact working scale: enough to hold dividend, and the product of quotient and divisor.
        $working = max(
            self::scaleOf($absDividend),
            $scale + self::scaleOf($absDivisor),
        ) + 1;

        $remainder = bcsub($absDividend, bcmul($truncated, $absDivisor, $working), $working);

        $comparedToHalf = bccomp(
            bcmul('2', $remainder, $working),
            bcmul(self::unit($scale), $absDivisor, $working),
            $working,
        );

        $rounded = self::applyRounding(
            truncated: $truncated,
            hasRemainder: !self::isZero($remainder),
            comparedToHalf: $comparedToHalf,
            negative: $negative,
            scale: $scale,
            mode: $mode,
        );

        if (null === $rounded) {
            return null;
        }

        return self::normaliseZero($negative ? '-' . $rounded : $rounded);
    }

    /**
     * The one place rounding is decided, for both multiplication and division.
     *
     * Works on the magnitude only — `$truncated` is non-negative and `$negative` carries the sign —
     * so "away from zero" is simply "increment", and the directional modes reduce to it. Keeping sign
     * out of the arithmetic is what makes the negative cases correct by construction rather than by
     * an extra branch per mode.
     *
     * @param string $truncated magnitude truncated toward zero, already at `$scale`
     * @param bool $hasRemainder whether anything at all was discarded
     * @param int $comparedToHalf -1, 0 or 1: discarded part against half a unit
     *
     * @return string|null null only for RoundingMode::Unnecessary when rounding would be needed
     */
    private static function applyRounding(
        string $truncated,
        bool $hasRemainder,
        int $comparedToHalf,
        bool $negative,
        int $scale,
        RoundingMode $mode,
    ): ?string {
        if (!$hasRemainder) {
            return $truncated;
        }

        $increment = match ($mode) {
            RoundingMode::Unnecessary => null,
            RoundingMode::Up => true,
            RoundingMode::Down => false,
            RoundingMode::Ceiling => !$negative,
            RoundingMode::Floor => $negative,
            RoundingMode::HalfUp => $comparedToHalf >= 0,
            RoundingMode::HalfDown => $comparedToHalf > 0,
            RoundingMode::HalfEven => $comparedToHalf > 0
                || (0 === $comparedToHalf && self::isLastDigitOdd($truncated)),
        };

        if (null === $increment) {
            return null;
        }

        return $increment ? bcadd($truncated, self::unit($scale), $scale) : $truncated;
    }

    private static function isLastDigitOdd(string $magnitude): bool
    {
        $lastDigit = substr($magnitude, -1);

        return \in_array($lastDigit, ['1', '3', '5', '7', '9'], true);
    }

    /** One unit at `$scale`: "1" at scale 0, "0.001" at scale 3. */
    private static function unit(int $scale): string
    {
        return 0 === $scale ? '1' : '0.' . str_repeat('0', $scale - 1) . '1';
    }

    /** Half a unit at `$scale`: "0.5" at scale 0, "0.0005" at scale 3. */
    private static function halfUnit(int $scale): string
    {
        return '0.' . str_repeat('0', $scale) . '5';
    }

    /**
     * Collapse "-0.000" to "0.000".
     *
     * Rounding a small negative toward zero produces a negative zero, and so does Postgres in places.
     * Two spellings of zero would break every `===` and every equality assertion downstream, so there
     * is exactly one.
     */
    private static function normaliseZero(string $value): string
    {
        if (str_starts_with($value, '-') && self::isZero($value)) {
            return substr($value, 1);
        }

        return $value;
    }
}

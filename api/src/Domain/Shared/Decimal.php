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

    /**
     * The largest scale `assertScale()` admits — a PRACTICAL bound, and deliberately not bcmath's.
     *
     * **Phrased as `assertScale()`'s bound rather than "the largest scale this class will compute at",
     * which is what it said until round 14 and which `multiplyExact()` falsifies.** That method is a fifth
     * entry point, computes at `scaleOf(left) + scaleOf(right)`, and asserts nothing — correctly, because
     * it needs no rounding and so has no target scale to check against. The hazard was MEASURED rather
     * than assumed: a factor at scale 400000 costs 2ms and the cost is linear, so this constant's own
     * rationale below (a hostile caller failing in microseconds instead of allocating gigabytes) still
     * holds. What was wrong was only the claim to bound everything the class computes.
     *
     * bcmath's own ceiling is `INT_MAX` (2147483647), and enforcing *that* was the first fix attempted here.
     * It is the wrong bound, and finding out why was worth more than the finding that prompted it: a scale of
     * two billion is not rejected by bcmath at all, it is **accepted**, and bcmath then tries to compute two
     * billion digits. [Verified: a `ratioTo()` at scale 2147483640 did not raise — it ran until the probe was
     * killed at 120 seconds.] So the real hazard at the top of the range is an unbounded allocation and a hung
     * request, not a leaked `ValueError`, and a guard set at bcmath's ceiling lets every such call straight
     * through while reporting the containment promise as kept.
     *
     * 1000 is a policy choice and is stated as one rather than dressed up as a limit. The justification: it is
     * three orders of magnitude above anything this domain uses — `Rate::FRACTION_SCALE` is **12** and money
     * columns carry **4** — so no legitimate caller comes near it, while a buggy or hostile one fails in
     * microseconds instead of allocating gigabytes. If a real use ever needs more, raise it deliberately with a
     * measurement attached; never to make a test pass.
     */
    public const int MAX_SCALE = 1000;

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

    /** Digits before the decimal point, ignoring sign and leading zeroes. */
    public static function integerDigits(string $value): int
    {
        $magnitude = ltrim($value, '-');
        $dot = strpos($magnitude, '.');
        $integerPart = false === $dot ? $magnitude : substr($magnitude, 0, $dot);
        $significant = ltrim($integerPart, '0');

        return '' === $significant ? 1 : \strlen($significant);
    }

    /**
     * Sum, EXACTLY — a narrowing scale is refused rather than truncated.
     *
     * `bcadd` truncates at its scale argument, so this method used to lose digits silently and outside
     * `applyRounding()`, contradicting this class's own promise that all rounding lives in one place.
     * `add('0.1', '0.19', 1)` returned `0.2`, discarding 0.09 with no diagnostic and no `RoundingMode` to
     * consult. Certification round 5 found it; all four callers at the time were safe *by construction*
     * (their operands already sat at the target scale), which is a property of those callers and not of this
     * method — and the first Wave 1 caller accumulating at a document scale would have been wrong.
     *
     * The sum is computed at full width and then required to fit. A caller that genuinely wants to round
     * must say so, through {@see self::rescale()} with an explicit mode.
     *
     * @throws \LogicException if `$scale` is negative, or if the result does not fit in `$scale` without
     *                         rounding
     */
    public static function add(string $left, string $right, int $scale): string
    {
        self::assertScale($scale, 'add');

        return self::exactlyAt(
            bcadd($left, $right, max(self::scaleOf($left), self::scaleOf($right))),
            $scale,
            'add',
        );
    }

    /**
     * Difference, EXACTLY. See {@see self::add()} for why a narrowing scale is refused.
     *
     * @throws \LogicException if `$scale` is negative, or if the result does not fit in `$scale` without
     *                         rounding
     */
    public static function subtract(string $left, string $right, int $scale): string
    {
        self::assertScale($scale, 'subtract');

        return self::exactlyAt(
            bcsub($left, $right, max(self::scaleOf($left), self::scaleOf($right))),
            $scale,
            'subtract',
        );
    }

    /**
     * A scale must be a count of decimal places, so it cannot be negative.
     *
     * **This is the bcmath containment boundary, and that is the whole reason it exists.** Every bcmath
     * function raises `ValueError: bcdiv(): Argument #3 ($scale) must be between 0 and 2147483647` for a
     * negative scale, and round 11 found all four scale-taking methods here passing one straight through.
     * That leaks twice: CLAUDE.md § Architecture requires bcmath to stay an implementation detail inside this
     * class and never reach a signature — and an exception type IS part of a signature — while the message
     * names a function the caller has never heard of and cannot find in `Domain/`.
     *
     * All four entry points, not just the one a review happened to arrive through: `add`, `subtract`,
     * `rescale` and `divide`. A guard on one of a class of call sites is the recurring defect this project
     * records in CLAUDE.md § Gotchas, twice over.
     *
     * `\LogicException` for the same reason {@see self::exactlyAt()} uses one: a negative scale is a
     * programming error at the call site, never invalid user input. Note the boundary is `< 0`, not falsy —
     * a scale of **zero** is a legitimate request for an integer result and is accepted.
     *
     * @throws \LogicException if `$scale` is negative
     */
    private static function assertScale(int $scale, string $operation): void
    {
        // BOTH ENDS. The first version of this guard checked `< 0` only, while the docblock above quoted
        // bcmath's range verbatim — "must be between 0 and 2147483647" — and three separate places asserted
        // that a ValueError could no longer escape: this method's docblock, `Money::ratioTo()`'s `@throws`,
        // and a test whose own NAME says "none leaks a bcmath ValueError". All three were false at the upper
        // bound, on all four entry points, and round 12 found it one round after the lower half landed.
        //
        // The lesson is the one this repository keeps relearning from the other direction: a guard derived
        // from a stated range must enforce the WHOLE range, and quoting the bound in prose while enforcing
        // half of it is worse than not quoting it, because a reader checks the prose.
        if ($scale < 0 || $scale > self::MAX_SCALE) {
            throw new \LogicException(\sprintf(
                'Decimal::%s() was given a scale of %d. A scale is a count of decimal places and must be '
                . 'between 0 and %d; zero is valid and means an integer result. Beyond that bcmath itself '
                . 'refuses, and letting it do so would leak the arithmetic implementation out of Domain/. Above the maximum bcmath does NOT refuse -- it allocates -- so that end is a hung request rather than an exception.',
                $operation,
                $scale,
                self::MAX_SCALE,
            ));
        }
    }

    /**
     * Narrow to `$scale`, or refuse.
     *
     * A `LogicException` rather than a domain exception on purpose: reaching it means a *caller* asked an
     * exact operation to discard digits, which is a programming error at the call site, not invalid user
     * input. `Money` and `Rate` already take a `RoundingMode` wherever a user-facing rounding is legitimate.
     */
    private static function exactlyAt(string $value, int $scale, string $operation): string
    {
        $narrowed = self::rescale($value, $scale, RoundingMode::Unnecessary);

        if (null === $narrowed) {
            throw new \LogicException(\sprintf(
                'Decimal::%s() would have to round %s to fit %d decimal place(s), and it is an exact '
                . 'operation. bcadd/bcsub truncate silently, which is why this is refused rather than '
                . 'discarded — use Decimal::rescale() with an explicit RoundingMode if rounding is intended.',
                $operation,
                $value,
                $scale,
            ));
        }

        return $narrowed;
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
     * the caller turns that into a domain exception, so this class raises nothing about ROUNDING itself.
     *
     * @throws \LogicException if `$scale` is negative
     */
    public static function rescale(string $value, int $scale, RoundingMode $mode): ?string
    {
        self::assertScale($scale, 'rescale');

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
     * @throws \LogicException if `$scale` is negative
     */
    public static function divide(string $dividend, string $divisor, int $scale, RoundingMode $mode): ?string
    {
        self::assertScale($scale, 'divide');

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

        // THE DERIVED SCALE, ASSERTED TOO — not just the caller's. `assertScale()` at the top of this method
        // bounds `$scale`, and that is not sufficient: the working scale ADDS the divisor's own scale plus one,
        // so a caller passing exactly `MAX_SCALE` overflows bcmath here rather than at the entry point.
        // [Verified: `ratioTo(..., 2147483647, ...)` raised `ValueError: bcmul(): Argument #3 ($scale) must be
        // between 0 and 2147483647` with the entry guard already in place.]
        //
        // Checked on the DERIVED value rather than by lowering `MAX_SCALE` behind a headroom constant, because
        // the addition depends on the divisor's scale at runtime — a fixed headroom would be a magic number
        // that is either too small to be safe or too large to be honest, and the exact quantity is right here.
        if ($working > self::MAX_SCALE) {
            throw new \LogicException(\sprintf(
                'Decimal::divide() needs an internal working scale of %d to stay exact for a scale of %d, and '
                . 'the maximum is %d. Ask for fewer decimal places: the working scale adds the divisor\'s own '
                . 'scale plus one, so a scale near the maximum cannot be computed exactly.',
                $working,
                $scale,
                self::MAX_SCALE,
            ));
        }

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

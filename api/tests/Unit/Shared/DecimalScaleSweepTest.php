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
 * Residues R3-2 and R3-3, closed by sweeping rather than by one example each.
 *
 * **R3-2 — `divide()`'s working scale.** The term is
 * `max(scaleOf($absDividend), $scale + scaleOf($absDivisor))`, and a single 15-decimal-dividend case only ever
 * drives ONE arm of that `max`. If the other arm were wrong the result would be off by an ulp — a wrong number
 * in a legal document, and exactly the class of defect no single example catches. This sweep crosses dividend
 * scale, divisor scale and target scale so that each arm dominates in some case and they tie in others.
 *
 * **No hand-computed expected values anywhere in this file**, deliberately: `CLAUDE.md` § Gotchas records a
 * hand-computed literal (`999999999999999 × 100`) as its own source of error. Instead each result is checked
 * against the two properties that define correct rounded division, both verified with bcmath at a scale far
 * above the one under test:
 *   1. the result carries EXACTLY the requested scale — a working-scale bug usually shows up here first;
 *   2. the result is the nearest representable value at that scale, i.e. `|result − dividend/divisor|` is at
 *      most half an ulp. A too-small working scale makes the quotient wrong in the last place, which breaks
 *      this even when the digit count looks right.
 * Properties rather than literals also means the sweep can be widened without hand-arithmetic per case.
 *
 * **R3-3 — `scaleOf()` on dotless values.** It survived a mutant returning garbage for every value without a
 * dot, purely by luck of consumer shape: nothing then in the tree passed it an integer string and cared. The
 * next consumer would — a formatter, or a `NUMERIC(19,4)` decimal-count check in a Doctrine type — and it would
 * break silently. Pinned directly here, so the function is covered by its own contract rather than by whoever
 * happens to call it.
 */
/**
 * AN UNKILLED MUTANT, recorded rather than hidden: the `+ 1` on the working scale survives removal.
 *
 * `$working = max(armA, armB) + 1`. With the `+ 1` deleted, all 33 cases here still pass, and I could not
 * construct an input that distinguishes them. The reason appears to be that every bcmath call using
 * `$working` is already exact at `max(armA, armB)`:
 *   - `bcmul($truncated, $absDivisor, …)` needs `$scale + scaleOf($divisor)` — that is armB;
 *   - `bcsub($absDividend, product, …)` needs `max(scaleOf($dividend), armB)` — that is the max itself;
 *   - `bcmul('2', $remainder, …)` does not increase scale;
 *   - `bcmul(unit($scale), $absDivisor, …)` needs `$scale + scaleOf($divisor)` — armB again.
 * So the `+ 1` reads as redundant headroom.
 *
 * **Stated as an observation, NOT as an impossibility, and deliberately not acted on.** `CLAUDE.md`
 * § Gotchas forbids recording a coverage gap as impossible — three such claims were refuted in one session,
 * and a documented impossibility gets read once and never re-tested, which makes the false claim the more
 * expensive artifact. And removing precision headroom from money arithmetic on the strength of my own
 * reading is exactly the change that should face a reviewer first. So: either a later round finds the input
 * that kills this mutant, or the `+ 1` is removed deliberately with this reasoning attached. What must not
 * happen is it sitting here unremarked.
 *
 * ROUND 9 CONFIRMED THIS INDEPENDENTLY and more thoroughly: a reviewer ran 864,920 cases (dividend and
 * divisor scales 0-12, negatives, seven target scales, all eight rounding modes) and got byte-identical
 * output with and without the `+ 1`, then proved algebraically that every operand is exactly
 * representable at `max(armA, armB)`. So it is redundant rather than merely unkilled — and the decision
 * to record it instead of removing it still stands, because removing precision headroom from money
 * arithmetic is a deliberate change, not a tidy-up. There is no test for it, deliberately: a test that
 * cannot fail inflates the count and pins nothing (round 9 filed the `assertSame(1, 1)` marker that used
 * to sit here).
 */
#[CoversClass(Decimal::class)]
final class DecimalScaleSweepTest extends TestCase
{
    /**
     * R3-2: the quotient carries exactly the requested scale and is correctly rounded, across the sweep.
     */
    #[DataProvider('divisionSweep')]
    public function testDivideIsCorrectlyRoundedAtExactlyTheRequestedScale(
        string $dividend,
        string $divisor,
        int $scale,
    ): void {
        $result = Decimal::divide($dividend, $divisor, $scale, RoundingMode::HalfUp);

        self::assertIsString($result, 'HalfUp must always produce a value');

        // Property 1 — exactly the requested scale, no more and no fewer digits.
        self::assertSame($scale, Decimal::scaleOf($result), \sprintf(
            '%s / %s at scale %d gave "%s", whose scale is %d',
            $dividend,
            $divisor,
            $scale,
            $result,
            Decimal::scaleOf($result),
        ));

        // Property 2 — nearest representable value at that scale. Computed at scale + 30 so the reference is
        // far more precise than anything the implementation's working scale could produce.
        $reference = bcdiv($dividend, $divisor, $scale + 30);
        $error = bcsub($result, $reference, $scale + 30);
        $halfUlp = bcdiv('5', bcpow('10', (string) ($scale + 1), 0), $scale + 30);

        self::assertLessThanOrEqual(0, bccomp(self::absolute($error), $halfUlp, $scale + 30), \sprintf(
            '%s / %s at scale %d gave "%s"; the true quotient is ~%s, an error of %s which exceeds half an '
            . 'ulp (%s). That is the signature of a working scale that is too small.',
            $dividend,
            $divisor,
            $scale,
            $result,
            $reference,
            $error,
            $halfUlp,
        ));
    }

    /**
     * The sweep. Each row states WHICH arm of the `max()` it drives, because a case whose purpose is not
     * written down is a case somebody deletes.
     *
     * @return iterable<string, array{string, string, int}>
     */
    public static function divisionSweep(): iterable
    {
        // Arm A dominates: the dividend's own scale exceeds scale + divisor scale.
        yield 'dividend scale dominates, integer divisor' => ['1.234567890123456', '7', 2];
        yield 'dividend scale dominates, scale 0' => ['1.234567890123456', '3', 0];
        yield 'dividend scale dominates by one' => ['0.1234', '2', 2];

        // Arm B dominates: scale + divisor scale exceeds the dividend's scale.
        yield 'target scale dominates' => ['1', '3', 12];
        yield 'divisor scale dominates' => ['1', '0.000000000003', 4];
        yield 'both divisor and target scale contribute' => ['7', '0.0003', 10];

        // The arms TIE, which is the boundary a max() is most likely to get wrong by one.
        yield 'arms tie exactly' => ['0.123', '0.12', 1];
        yield 'arms tie at zero' => ['5', '2', 0];

        // Signs, because the implementation divides magnitudes and reapplies the sign.
        yield 'negative dividend' => ['-1', '3', 6];
        yield 'negative divisor' => ['1', '-3', 6];
        yield 'both negative' => ['-1', '-3', 6];

        // Exact division must not be perturbed by the working scale either.
        yield 'exact, terminating' => ['1', '8', 3];
        yield 'exact, terminating below the target scale' => ['1', '4', 6];

        // Ties, where a half-ulp error and a rounding-mode error look alike.
        yield 'a true half, rounds away from zero' => ['1', '2', 0];
        yield 'a true half, negative' => ['-1', '2', 0];
        yield 'half at the last place' => ['0.125', '1', 2];

        // A ZERO DIVIDEND, which round 9 found absent from the entire unit suite. Behaviour is already
        // correct — every mode yields '0.00' and Unnecessary does not refuse — so this is a missing pin
        // rather than a defect, and a zero-total document is a real shape in a billing system.
        yield 'zero dividend' => ['0', '3', 2];
        yield 'zero dividend, negative divisor' => ['0', '-3', 2];

        // The domain's real shapes: TND's three decimals and a 12-decimal rate.
        yield "TND, a millime" => ['1', '1000', 3];
        yield 'a rate at full 12-decimal precision' => ['1', '7', 12];
        yield 'the largest amount over a small divisor' => ['999999999999999.999', '3', 3];
    }

    /**
     * ARM A OF THE `max()`, which the property sweep above does NOT reach — and finding that out is the whole
     * point of R3-2 rather than an aside.
     *
     * Dropping `scaleOf($absDividend)` from the working scale passed all 20 property cases. The reason is that
     * arm A does not usually change a DIGIT: it guards the `bcsub($absDividend, …, $working)` that computes the
     * remainder, so when the dividend has more decimals than `$scale + scaleOf($divisor)`, the subtraction
     * truncates the dividend itself and the remainder comes out as ZERO. The quotient's digits are unaffected —
     * what breaks is `hasRemainder`, so an INEXACT division reports itself as exact.
     *
     * That is invisible to HalfUp, which rounds the same either way, and decisive for the two modes whose whole
     * behaviour depends on whether a remainder exists:
     *   - `Ceiling` must round away from zero on ANY remainder — a lost remainder silently rounds DOWN;
     *   - `Unnecessary` must return null on any remainder — a lost remainder silently returns a value, which
     *     defeats the one mode that exists to refuse a lossy operation.
     * A wrong number and a silent loss of precision respectively, in a billing domain.
     *
     * **CORRECTION (round 9), because the sentence here was false.** It read "arm A is not redundant; it was
     * simply untested". Arm A is load-bearing — that part is verified — but it was NOT untested:
     * `DecimalTest::testDivisionSeesADividendWiderThanTheTargetScalePlusTheDivisor` has pinned it since round
     * 3 and its own docblock says so, and the arm-A mutant produces THREE failures, one of them that
     * pre-existing case. The residue R3-2 said "only partly covered", not "untested" — so the claim
     * misquoted the residue it cited. What these two cases add is coverage of the `Ceiling`/`Unnecessary`
     * consequence, which is a different failure mode from the round-3 case. Asserting a coverage fact without
     * running the mutant is the exact sin § Gotchas records; it is recorded here rather than quietly edited.
     */
    public function testCeilingSeesARemainderTheDividendScaleWouldOtherwiseHide(): void
    {
        // 0.05 / 1 at scale 0. Arm B is 0 + 0 = 0; arm A is 2. Without arm A the remainder truncates to zero
        // and Ceiling rounds DOWN to 0 — for a value that is strictly greater than zero.
        self::assertSame('1', Decimal::divide('0.05', '1', 0, RoundingMode::Ceiling));
        self::assertSame('-1', Decimal::divide('-0.05', '1', 0, RoundingMode::Floor));
    }

    public function testUnnecessaryRefusesAnInexactDivisionTheDividendScaleWouldOtherwiseHide(): void
    {
        // Same shape. RoundingMode::Unnecessary exists to REFUSE a lossy operation; without arm A it would
        // report this exact and hand back a value, which is the precise failure that mode prevents.
        self::assertNull(Decimal::divide('0.05', '1', 0, RoundingMode::Unnecessary));

        // And it must still ACCEPT a genuinely exact division, or it would be a check that cannot pass.
        self::assertSame('2', Decimal::divide('4', '2', 0, RoundingMode::Unnecessary));
        self::assertSame('0.25', Decimal::divide('1', '4', 2, RoundingMode::Unnecessary));
    }

    /**
     * R3-3: `scaleOf()` pinned by its own contract, dotless values included.
     */
    #[DataProvider('scaleOfCases')]
    public function testScaleOfCountsDigitsAfterTheDot(string $value, int $expected): void
    {
        self::assertSame($expected, Decimal::scaleOf($value), \sprintf('scaleOf("%s")', $value));
    }

    /** @return iterable<string, array{string, int}> */
    public static function scaleOfCases(): iterable
    {
        // THE MUTANT THAT SURVIVED: every one of these is dotless, and a version returning garbage for the
        // dotless branch passed the whole suite because nothing then in the tree cared.
        yield 'a bare integer' => ['5', 0];
        yield 'zero' => ['0', 0];
        yield 'a negative integer' => ['-42', 0];
        yield 'the largest integer amount' => ['999999999999999', 0];
        yield 'a long dotless string' => ['1234567890123456789', 0];

        yield 'one decimal' => ['1.5', 1];
        yield 'three decimals, TND' => ['0.100', 3];
        yield 'twelve decimals, a rate' => ['0.000000000001', 12];
        yield 'trailing zeroes still count' => ['1.2300', 4];
        yield 'negative with decimals' => ['-1.25', 2];
        yield 'a leading dot' => ['.5', 1];
        yield 'a trailing dot counts zero decimals' => ['5.', 0];
    }

    private static function absolute(string $value): string
    {
        return str_starts_with($value, '-') ? substr($value, 1) : $value;
    }
}

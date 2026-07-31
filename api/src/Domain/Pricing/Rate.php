<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Pricing;

use Twes\Domain\Pricing\Exception\InvalidRate;
use Twes\Domain\Shared\Decimal;
use Twes\Domain\Shared\RoundingMode;

/**
 * A proportion — a profit rate, a VAT rate, a discount.
 *
 * Deliberately not a `Money`: a rate is dimensionless, and giving it a currency would let it be
 * rounded to that currency's scale. Rounding a rate to three decimals then multiplying by it is how a
 * price drifts away from the rate displayed next to it.
 *
 * Stored as a **fraction** (30% is `0.300000000000`) because that is the form arithmetic uses, and exposed
 * as a **percentage** because that is the form humans type. Both are canonical strings, so comparing
 * a rate across the API, the Angular admin and the Flutter client is a string comparison with no
 * formatting ambiguity.
 *
 * A negative rate is valid: selling below cost is a real commercial decision — clearance, a loss
 * leader — and clamping it to zero would hide it rather than surface it.
 */
final readonly class Rate
{
    /**
     * Decimal places kept on the fraction.
     *
     * **Twelve, not six** (developer ruling, 2026-07-29). Six was not enough, and the failure was not
     * theoretical: a cost of 10 000.000 TND with a selling price of 10 000.001 has a true rate of
     * 0.0000001 — seven decimals — so at six it rounded to zero. The form then displayed `0.0000 %` for a
     * product being sold above cost, and a later cost change rebuilt the price from that zero and deleted
     * the millime of profit outright.
     *
     * Twelve holds both of the cases that motivated the change exactly:
     *
     *     cost 10 000.000, net 10 000.001    -> rate 0.000000100000  (0.0000100000 %)
     *     cost  1 000 000.000, net 1 000 000.500 -> rate 0.000000500000  (0.0000500000 %)
     *
     * This is half the fix. The other half is {@see ProductPricing}, which records WHICH field the user
     * typed so that the typed value is never rebuilt from a derived one. Precision alone would only push
     * the boundary further out; the two together remove it for any realistic product.
     */
    public const int FRACTION_SCALE = 12;

    /**
     * Decimal places on the percentage form.
     *
     * Exactly FRACTION_SCALE - 2, so converting between the two forms is a shift of the decimal point
     * and never itself a rounding.
     *
     * Ten decimals is more than anyone wants to read; that is a *display* concern and belongs to `UI/`
     * and the clients, which format for the locale. The canonical string stays fully precise so that
     * comparing a rate across PHP, TypeScript and Dart is an exact string comparison.
     */
    public const int PERCENTAGE_SCALE = 10;

    /**
     * Digits allowed before the decimal point.
     *
     * `profit_rate` is `NUMERIC(27,12)`: twelve fraction decimals plus fifteen integer digits, matching
     * `Money::MAX_INTEGER_DIGITS` so that any rate derivable from two representable amounts fits.
     *
     * **Fifteen, not three** — and the correction matters more than the number. Three was chosen to match a
     * `NUMERIC(15,12)` column, and a review showed the column was simply too narrow: `withCost()` turns a
     * *derived* rate into the authored one, and a one-millime cost with a typed price of 1000.000 derives
     * 999999 — four integer digits from two entirely ordinary amounts for a 3-decimal currency. Worse, that
     * bound was reached from `profitRate()`, a **read accessor**, so an aggregate that constructed and
     * persisted perfectly legally threw on every subsequent read of its rate. A getter that throws on
     * valid stored state is a 500 on a product page, not a validation error.
     *
     * The bound is kept as a sanity guard rather than removed — a rate of 10^15 is a typo, not a margin —
     * but it is now wide enough that the *derived* path cannot reach it from any pair of amounts a
     * `NUMERIC(19,4)` column can hold, and {@see PriceCalculator::profitRateFromNet()} returns null rather
     * than throwing if it somehow does.
     */
    public const int MAX_INTEGER_DIGITS = 15;

    /**
     * Whether a fraction is small enough to be a `Rate`.
     *
     * Exists so the derived path can *ask* instead of catching: a computed rate that will not fit is a
     * null (an empty field), while a rate the user typed too large is a refusal. Same bound, two very
     * different obligations, and only a question can serve both.
     */
    public static function canHoldFraction(string $fraction): bool
    {
        return Decimal::isWellFormed($fraction)
            && Decimal::integerDigits($fraction) <= self::MAX_INTEGER_DIGITS;
    }

    /** @param string $fraction canonical decimal string at exactly FRACTION_SCALE decimals */
    private function __construct(private string $fraction)
    {
        if (Decimal::integerDigits($fraction) > self::MAX_INTEGER_DIGITS) {
            throw InvalidRate::tooLarge($fraction);
        }
    }

    /**
     * From the form a human types: `30` means 30%.
     *
     * @throws InvalidRate if malformed, or too precise to hold without rounding
     */
    public static function fromPercentage(string|int|float $percentage): self
    {
        // REFUSE a float, and note the widened union is what makes the refusal reachable at all — see
        // Money::multipliedBy() for the mechanism. This one is the most dangerous of the four sites round 5
        // found: with a bare `string|int`, fromFraction(0.30) coerced to int 0 and returned a rate of
        // ZERO, so a 30% margin silently became 0%. That is precisely the defect the authored_by design and
        // the 12-decimal rate exist to prevent, arriving through a different door.
        if (\is_float($percentage)) {
            throw InvalidRate::floatRefused($percentage);
        }

        $value = (string) $percentage;

        if (!Decimal::isWellFormed($value)) {
            throw InvalidRate::malformed($value);
        }

        // REFUSED BEFORE `Decimal::divide()`, and that ordering is the whole fix. Round 13 found this factory
        // raising `\LogicException` for an over-precise percentage while its sibling `fromFraction()` correctly
        // raised `InvalidRate` — a regression from round 12's own MAX_SCALE work. The two take different routes:
        // `fromFraction` goes through `Decimal::rescale()`, which returns null and lets the null arm below
        // report a domain error; `fromPercentage` goes through `Decimal::divide()`, whose DERIVED working-scale
        // guard fires FIRST and raises the exception type this codebase reserves for "a programming error at the
        // call site, never invalid user input". So a form field yielded a 500 rather than a 422, and the message
        // named `Decimal::divide()`'s internals to a caller that chose no scale at all — `FRACTION_SCALE` is a
        // constant.
        //
        // `PERCENTAGE_SCALE` is the exact bound rather than an arbitrary one: dividing by 100 shifts the point
        // two places, so a percentage holds exactly `FRACTION_SCALE - 2` decimals, and this class already names
        // that number.
        //
        // **NORMALISED THROUGH `rescale()` RATHER THAN MEASURED WITH `scaleOf()`, WHICH ROUND 13 GOT WRONG.**
        // `scaleOf()` counts the decimals that are WRITTEN; the question is how many are SIGNIFICANT. So round
        // 13's version refused `19.00000000000` — a value needing *zero* decimals beyond `0.19` — while its
        // sibling `fromFraction()` accepted a scale-20 spelling of the same rate, and `Money::of()` documents
        // trailing zeros as losing nothing ("`0.1000` in TND is `0.100`"). It also told the caller the value
        // "needs more than 12 decimal places", which was simply untrue of it. Round 14 found it as a REGRESSION
        // against `da4d731`, reachable from any client that formats a rate field with `toStringAsFixed`.
        //
        // Two things have to happen in this order, and doing only the first leaves the 500 in place:
        //   1. refuse a value that cannot be held at `PERCENTAGE_SCALE` WITHOUT rounding — `Unnecessary` is what
        //      makes "significant" the test, since dropping trailing zeros needs no rounding;
        //   2. divide the NORMALISED string, never the original. `Decimal::divide()`'s derived working scale is
        //      `max(scaleOf(dividend), scale + scaleOf(divisor)) + 1`, so a legitimate `19.` + 2000 zeros passes
        //      step 1 and then asks for a working scale of 2001 against `MAX_SCALE` of 1000 — the same
        //      `\LogicException`-instead-of-`InvalidRate` fault, one door further along.
        //      [Verified: dividing the raw value raises "needs an internal working scale of 2001"; dividing the
        //      normalised `19.0000000000` returns `0.190000000000`.]
        $normalised = Decimal::rescale($value, self::PERCENTAGE_SCALE, RoundingMode::Unnecessary);

        if (null === $normalised) {
            throw InvalidRate::tooPrecise($value);
        }

        $fraction = Decimal::divide($normalised, '100', self::FRACTION_SCALE, RoundingMode::Unnecessary);

        // DEFENSIVE, and now honestly so: `$normalised` holds exactly `PERCENTAGE_SCALE` decimals, so dividing
        // it by 100 at `FRACTION_SCALE` is always exact and this arm is unreachable today. Kept rather than
        // replaced by `?? throw` because a `?? throw` asserts unreachability, and the next edit to either
        // constant could falsify that silently — whereas this arm just keeps reporting a domain error.
        if (null === $fraction) {
            throw InvalidRate::tooPrecise($value);
        }

        return new self($fraction);
    }

    /**
     * From the form arithmetic uses: `0.30` means 30%.
     *
     * @throws InvalidRate if malformed, or too precise to hold without rounding
     */
    public static function fromFraction(string|int|float $fraction): self
    {
        // REFUSE a float, and note the widened union is what makes the refusal reachable at all — see
        // Money::multipliedBy() for the mechanism. This one is the most dangerous of the four sites round 5
        // found: with a bare `string|int`, fromFraction(0.30) coerced to int 0 and returned a rate of
        // ZERO, so a 30% margin silently became 0%. That is precisely the defect the authored_by design and
        // the 12-decimal rate exist to prevent, arriving through a different door.
        if (\is_float($fraction)) {
            throw InvalidRate::floatRefused($fraction);
        }

        $value = (string) $fraction;

        if (!Decimal::isWellFormed($value)) {
            throw InvalidRate::malformed($value);
        }

        $normalised = Decimal::rescale($value, self::FRACTION_SCALE, RoundingMode::Unnecessary);

        if (null === $normalised) {
            throw InvalidRate::tooPrecise($value);
        }

        return new self($normalised);
    }

    public static function zero(): self
    {
        return self::fromFraction('0');
    }

    /** The fraction form: 30% is "0.300000000000". Multiply an amount by this to get the rate's share. */
    public function fraction(): string
    {
        return $this->fraction;
    }

    /** The percentage form: 30% is "30.0000000000". Exact — the conversion never rounds. */
    public function percentage(): string
    {
        $shifted = Decimal::multiplyExact($this->fraction, '100');

        // Exact by construction: PERCENTAGE_SCALE is FRACTION_SCALE - 2, so multiplying by 100 leaves
        // precisely PERCENTAGE_SCALE decimals and there is nothing to round away.
        return Decimal::rescale($shifted, self::PERCENTAGE_SCALE, RoundingMode::Unnecessary)
            ?? throw new \LogicException('Percentage conversion should be exact for ' . $this->fraction);
    }

    /**
     * `1 + fraction` — the markup multiplier.
     *
     * Multiplying a cost by this gives the net selling price in one operation, which matters: doing it
     * as `cost + (cost * rate)` rounds twice and can land a millime away from this result.
     */
    public function markupMultiplier(): string
    {
        return Decimal::add('1', $this->fraction, self::FRACTION_SCALE);
    }

    public function isZero(): bool
    {
        return Decimal::isZero($this->fraction);
    }

    public function isNegative(): bool
    {
        return Decimal::isNegative($this->fraction);
    }

    public function equals(self $other): bool
    {
        return 0 === Decimal::compare($this->fraction, $other->fraction);
    }
}

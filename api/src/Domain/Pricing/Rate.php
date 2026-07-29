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

    /** @param string $fraction canonical decimal string at exactly FRACTION_SCALE decimals */
    private function __construct(private string $fraction) {}

    /**
     * From the form a human types: `30` means 30%.
     *
     * @throws InvalidRate if malformed, or too precise to hold without rounding
     */
    public static function fromPercentage(string|int $percentage): self
    {
        $value = (string) $percentage;

        if (!Decimal::isWellFormed($value)) {
            throw InvalidRate::malformed($value);
        }

        $fraction = Decimal::divide($value, '100', self::FRACTION_SCALE, RoundingMode::Unnecessary);

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
    public static function fromFraction(string|int $fraction): self
    {
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

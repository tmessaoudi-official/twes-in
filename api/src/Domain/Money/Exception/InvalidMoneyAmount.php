<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Money\Exception;

use Twes\Domain\Money\Currency;

final class InvalidMoneyAmount extends \InvalidArgumentException
{
    public static function floatRefused(float $amount): self
    {
        return new self(\sprintf(
            'Refusing the float %s as a money amount. Binary floating point cannot represent most '
            . 'decimal fractions — 0.1 is not 0.1 — so a float has already lost precision before it '
            . 'arrives here. Pass a decimal string instead: Money::of(\'%s\', ...).',
            var_export($amount, true),
            // var_export gives the shortest round-tripping form, which is the closest honest rendering
            // of what the caller actually had.
            var_export($amount, true),
        ));
    }

    public static function outOfRange(string $amount): self
    {
        return new self(\sprintf(
            'Amount "%s" has more than %d digits before the decimal point, so it does not fit a '
            . 'NUMERIC(19,4) money column. Refused here rather than at the database boundary, where '
            . 'the failure would surface mid-transaction with no context.',
            $amount,
            \Twes\Domain\Money\Money::MAX_INTEGER_DIGITS,
        ));
    }

    public static function malformed(string $amount): self
    {
        return new self(\sprintf(
            'Amount "%s" is not a plain decimal number. Expected an optional minus sign, digits, and '
            . 'at most one decimal point — no thousands separators, no exponent, no surrounding space.',
            $amount,
        ));
    }

    public static function notRepresentable(string $amount, Currency $currency): self
    {
        return new self(\sprintf(
            'Amount "%s" cannot be represented exactly in %s, which has %d decimal place(s). '
            . 'Round it deliberately with an explicit RoundingMode rather than letting it be truncated.',
            $amount,
            $currency->code(),
            $currency->scale(),
        ));
    }

    /**
     * The DIMENSIONLESS case: a ratio, rounded at a scale the caller chose.
     *
     * Separate from {@see self::roundingWouldLosePrecision()} because that one names the currency and its
     * scale, and neither is involved here. `Money::ratioTo()` returns a plain string precisely because the
     * ratio of two amounts is dimensionless, and it rounds at the scale the CALLER asked for — so reporting
     * `does not fit TND (3 decimal place(s))` to a caller who asked for twelve is a false statement in an
     * error message. It sends a reader to inspect the currency, which is not part of the failure, while the
     * number they actually chose is absent. Round 11 found the currency-shaped factory reused here.
     */
    public static function roundingWouldLosePrecisionAtScale(string $expression, int $scale): self
    {
        return new self(\sprintf(
            'Result "%s" does not fit %d decimal place(s) and RoundingMode::Unnecessary forbids rounding '
            . 'it. Choose a rounding mode, or ask for a higher scale.',
            $expression,
            $scale,
        ));
    }

    public static function roundingWouldLosePrecision(string $amount, Currency $currency): self
    {
        return new self(\sprintf(
            'Result "%s" does not fit %s (%d decimal place(s)) and RoundingMode::Unnecessary forbids '
            . 'rounding it. Choose a rounding mode, or keep the operation at a higher precision.',
            $amount,
            $currency->code(),
            $currency->scale(),
        ));
    }
}

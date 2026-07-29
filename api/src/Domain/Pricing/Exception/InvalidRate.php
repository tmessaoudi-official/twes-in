<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Pricing\Exception;

use Twes\Domain\Pricing\Rate;

final class InvalidRate extends \InvalidArgumentException
{
    public static function malformed(string $value): self
    {
        return new self(\sprintf(
            'Rate "%s" is not a plain decimal number. Expected an optional minus sign, digits, and at '
            . 'most one decimal point.',
            $value,
        ));
    }

    public static function tooLarge(string $fraction): self
    {
        return new self(\sprintf(
            'Rate fraction "%s" has more than %d digit(s) before the decimal point, so it does not fit a '
            . 'NUMERIC(27,12) column — %d fraction decimals leave only %d integer digits. Refused here '
            . 'rather than at the database boundary. A rate this large usually means a near-zero cost: '
            . 'check the cost rather than raising this bound.',
            $fraction,
            Rate::MAX_INTEGER_DIGITS,
            Rate::FRACTION_SCALE,
            Rate::MAX_INTEGER_DIGITS,
        ));
    }

    /**
     * A float arrived where a decimal string belongs.
     *
     * Mirrors {@see \Twes\Domain\Money\Exception\InvalidMoneyAmount::floatRefused()} deliberately: a rate
     * is as unforgiving as an amount, and 19.99 % is exactly as unrepresentable in binary floating point as
     * 19.99 TND. Round 5 found `fromFraction(0.30)` returning a rate of ZERO from a weak-mode caller,
     * because a `string|int` union coerces to int and 0.30 becomes 0.
     */
    public static function floatRefused(float $rate): self
    {
        return new self(\sprintf(
            'Refusing the float %s as a rate. Binary floating point cannot represent most decimal '
            . 'fractions, so a float has already lost precision before it arrives here — and a rate is '
            . 'multiplied by a money amount, so that loss becomes a wrong number on a legal document. '
            . 'Pass a decimal string instead: Rate::fromPercentage(\'%s\').',
            var_export($rate, true),
            var_export($rate, true),
        ));
    }

    public static function tooPrecise(string $value): self
    {
        return new self(\sprintf(
            'Rate "%s" needs more than %d decimal places as a fraction (%d as a percentage). It is '
            . 'refused rather than rounded, because a silently rounded rate produces a price that does '
            . 'not match the rate shown beside it.',
            $value,
            Rate::FRACTION_SCALE,
            Rate::PERCENTAGE_SCALE,
        ));
    }
}

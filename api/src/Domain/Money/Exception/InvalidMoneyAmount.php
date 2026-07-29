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

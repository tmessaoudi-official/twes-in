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
            . 'NUMERIC(15,12) column — %d fraction decimals leave only %d integer digits. Refused here '
            . 'rather than at the database boundary. A rate this large usually means a near-zero cost: '
            . 'check the cost rather than raising this bound.',
            $fraction,
            Rate::MAX_INTEGER_DIGITS,
            Rate::FRACTION_SCALE,
            Rate::MAX_INTEGER_DIGITS,
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

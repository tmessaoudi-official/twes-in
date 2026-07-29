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

final class UnknownCurrency extends \InvalidArgumentException
{
    public static function code(string $code): self
    {
        return new self(\sprintf(
            'Currency "%s" is not in twes-in\'s ISO 4217 registry, so its number of decimal places is '
            . 'unknown. It is refused rather than assumed to have two — a wrong scale is a wrong '
            . 'amount on a legal document. Add it to Currency::SCALES with its ISO 4217 minor unit.',
            $code,
        ));
    }
}

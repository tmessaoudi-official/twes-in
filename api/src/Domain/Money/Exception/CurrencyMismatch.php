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

final class CurrencyMismatch extends \LogicException
{
    public static function between(Currency $left, Currency $right): self
    {
        return new self(\sprintf(
            'Cannot combine %s with %s. Converting between currencies needs an exchange rate captured '
            . 'at the document date, which is an explicit operation and never an implicit one.',
            $left->code(),
            $right->code(),
        ));
    }
}

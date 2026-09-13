<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\Currency;

use App\Fiscal\Application\CurrencyScales;
use Symfony\Component\Intl\Currencies;

/** A currency's decimals as ISO 4217 publishes them, through the CLDR data symfony/intl ships. */
final readonly class IntlCurrencyScales implements CurrencyScales
{
    public function of(string $currency): int
    {
        $code = strtoupper($currency);
        // getFractionDigits answers CLDR's default of 2 for a code it does not know, which would hide a typo.
        if (!Currencies::exists($code)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an ISO 4217 currency.', $currency));
        }

        return Currencies::getFractionDigits($code);
    }
}

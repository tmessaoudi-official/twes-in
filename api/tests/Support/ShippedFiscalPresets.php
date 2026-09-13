<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Infrastructure\Currency\IntlCurrencyScales;
use App\Fiscal\Infrastructure\Preset\YamlFiscalPresets;

/** The presets and currency scales the application really ships, for use-case tests that should not invent a country. */
final class ShippedFiscalPresets
{
    public static function presets(): YamlFiscalPresets
    {
        return new YamlFiscalPresets(\dirname(__DIR__, 2).'/config/fiscal');
    }

    public static function scales(): CurrencyScales
    {
        return new IntlCurrencyScales();
    }
}

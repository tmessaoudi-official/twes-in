<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\Settings;

use App\Fiscal\Application\Preset\FiscalPresets;
use App\Settings\Application\CountrySettingDefaults;

/** A country's setting defaults are the `settings` its preset file names; a country without a preset gives none. */
final readonly class PresetCountrySettingDefaults implements CountrySettingDefaults
{
    public function __construct(private FiscalPresets $presets)
    {
    }

    public function of(string $country): array
    {
        return $this->presets->has($country) ? $this->presets->get($country)->settings : [];
    }
}

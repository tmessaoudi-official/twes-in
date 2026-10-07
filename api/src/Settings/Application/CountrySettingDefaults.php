<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

/**
 * The defaults a country gives some settings, where the custom differs by country and the law says nothing: what a
 * company of that country starts from before it chooses. Supporting another country is data, so they come from its
 * preset, never from code.
 */
interface CountrySettingDefaults
{
    /** @return array<string, mixed> by setting key; a key the country is silent about is left out */
    public function of(string $country): array;
}

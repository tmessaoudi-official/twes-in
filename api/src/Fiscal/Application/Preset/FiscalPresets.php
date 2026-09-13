<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

/** The countries twes-in knows the taxes of, one preset each (docs/SPEC.md § 3 Fiscal presets). */
interface FiscalPresets
{
    /** @return list<string> the preset keys, sorted; a key is the country's ISO code */
    public function keys(): array;

    public function has(string $key): bool;

    /** @throws UnknownFiscalPreset */
    public function get(string $key): FiscalPreset;
}

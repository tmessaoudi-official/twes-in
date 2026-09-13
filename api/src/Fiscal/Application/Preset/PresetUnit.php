<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

final readonly class PresetUnit
{
    /**
     * @param string                $code     a UN/ECE Recommendation 20 code (EN 16931 BT-130)
     * @param array<string, string> $names    by language
     * @param int                   $decimals how many decimals a quantity in this unit may carry
     */
    public function __construct(
        public string $code,
        public array $names,
        public int $decimals,
        public int $sortOrder,
    ) {
    }
}

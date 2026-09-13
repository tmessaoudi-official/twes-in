<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

use App\Fiscal\Domain\TaxFamily;
use App\Fiscal\Domain\TaxKind;

final readonly class PresetTaxComponent
{
    /**
     * @param array<string, string> $names     by language
     * @param string|null           $rate      a percentage, for the percentage and withholding kinds
     * @param string|null           $amount    in the preset's currency, for a fixed charge
     * @param string|null           $threshold in the preset's currency, for a withholding
     */
    public function __construct(
        public string $code,
        public array $names,
        public TaxKind $kind,
        public TaxFamily $family,
        public ?string $rate,
        public ?string $amount,
        public ?string $threshold,
        public bool $entersVatBase,
        public bool $isDefault,
        public int $sortOrder,
    ) {
    }
}

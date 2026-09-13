<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

use App\Fiscal\Domain\Calculation\RoundingPoint;
use App\Fiscal\Domain\Calculation\TaxBasis;

/**
 * One country's fiscal rules as read from its validated file. A company copies the tax components and units at
 * creation and edits its copy; the regimes stay operator-owned; the rounding rules and numbering defaults reach a
 * company through the settings engine (G3b).
 */
final readonly class FiscalPreset
{
    /**
     * @param list<string>                   $documentLanguages
     * @param list<PresetIdentifier>         $identifiers
     * @param list<PresetTaxComponent>       $taxComponents
     * @param list<PresetRegime>             $customerTaxRegimes
     * @param list<PresetRegime>             $companyVatRegimes
     * @param list<string>                   $invoiceMentions    translation keys printed on every invoice
     * @param array<string, PresetNumbering> $numbering          by document type
     * @param list<PresetUnit>               $units
     * @param PresetEstablishment            $establishment      how the country codes a company's establishments
     */
    public function __construct(
        public string $country,
        public string $currency,
        public int $minorUnit,
        public array $documentLanguages,
        public RoundingPoint $vatRoundingPoint,
        public TaxBasis $taxBasis,
        public array $identifiers,
        public array $taxComponents,
        public array $customerTaxRegimes,
        public array $companyVatRegimes,
        public array $invoiceMentions,
        public array $numbering,
        public array $units,
        public PresetEstablishment $establishment,
    ) {
    }

    public function component(string $code): PresetTaxComponent
    {
        foreach ($this->taxComponents as $component) {
            if ($component->code === $code) {
                return $component;
            }
        }

        throw new \OutOfBoundsException(\sprintf('The %s preset has no tax component %s.', $this->country, $code));
    }
}

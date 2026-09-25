<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Regime;

use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\PresetRegime;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxFamily;
use App\Tenancy\Domain\Company;

/**
 * The tax families a document leaves out: what its customer's regime does not charge and what its company's own VAT
 * regime does not charge (docs/SPEC.md § 7, 2026-09-20 04:15 — franchise brings none of the VAT; audit EXT-03, where a
 * franchise company printed "TVA non applicable" over the VAT it charged). The company's regime is its preset's.
 */
final readonly class ExcludedTaxFamilies
{
    public function __construct(private FiscalPresets $presets)
    {
    }

    /** The company's VAT regime as its preset declares it, or null when the preset offers no such regime. */
    public function companyRegime(Company $company): ?PresetRegime
    {
        $code = $company->getProfile()->vatRegime;

        return array_find($this->presets->get($company->getFiscalPreset())->companyVatRegimes, static fn (PresetRegime $regime): bool => $regime->code === $code);
    }

    /** @return list<TaxFamily> each family once, the customer's regime's first */
    public function of(Company $company, CustomerTaxRegime $customerRegime): array
    {
        $families = [];
        foreach ([...$customerRegime->getExcludedFamilies(), ...$this->companyRegime($company)->excludedFamilies ?? []] as $family) {
            $families[$family->value] = $family;
        }

        return array_values($families);
    }

    /** The code of the regime that leaves a family out, the customer's before the company's; null when neither does. */
    public function regimeLeavingOut(Company $company, CustomerTaxRegime $customerRegime, TaxFamily $family): ?string
    {
        if (\in_array($family, $customerRegime->getExcludedFamilies(), true)) {
            return $customerRegime->getCode();
        }
        $companyRegime = $this->companyRegime($company);

        return null !== $companyRegime && \in_array($family, $companyRegime->excludedFamilies, true) ? $companyRegime->code : null;
    }
}

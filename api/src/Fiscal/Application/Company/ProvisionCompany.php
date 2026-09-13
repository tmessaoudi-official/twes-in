<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Company;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\UnknownFiscalPreset;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\Unit;
use App\Fiscal\Domain\UnitRepository;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\EstablishmentRepository;
use App\Tenancy\Domain\NumberFormat;
use App\Tenancy\Domain\NumberingSeries;
use App\Tenancy\Domain\NumberingSeriesRepository;
use App\Tenancy\Domain\ResetPeriod;
use Psr\Clock\ClockInterface;

/**
 * A company copies its fiscal preset's tax components and units into its own rows, named in its language, and edits
 * that copy from then on (docs/SPEC.md § 3 Fiscal presets). It also starts with one default establishment, coded the
 * preset's way and named after the company, and one default numbering series per document type the preset numbers, on
 * that establishment. Idempotent: a company that already has taxes, units, establishments or series keeps them untouched.
 */
final readonly class ProvisionCompany
{
    public function __construct(
        private FiscalPresets $presets,
        private TaxComponentRepository $components,
        private UnitRepository $units,
        private EstablishmentRepository $establishments,
        private NumberingSeriesRepository $series,
        private CurrencyScales $scales,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string> what was copied, empty when there was nothing to copy
     *
     * @throws UnknownFiscalPreset
     */
    public function handle(Company $company): array
    {
        $preset = $this->presets->get($company->getFiscalPreset());
        $language = str_starts_with(strtolower($company->getLocale()), 'en') ? 'en' : 'fr';
        $now = $this->clock->now();
        $copied = [];

        if ([] === $this->components->ofCompany($company->getId())) {
            $scale = $this->scales->of($company->getCurrency());
            foreach ($preset->taxComponents as $component) {
                $this->components->save(TaxComponent::create(
                    $company,
                    $component->code,
                    $component->names[$language],
                    $component->family,
                    $component->rate,
                    $component->amount,
                    $component->threshold,
                    $component->entersVatBase,
                    $component->isDefault,
                    null,
                    $component->sortOrder,
                    $scale,
                    $now,
                ));
            }
            $copied[] = \sprintf('tax components of %s', $company->getName());
        }

        if ([] === $this->units->ofCompany($company->getId())) {
            foreach ($preset->units as $unit) {
                $this->units->save(Unit::create($company, $unit->code, $unit->names[$language], $unit->decimals, $unit->sortOrder, $now));
            }
            $copied[] = \sprintf('units of %s', $company->getName());
        }

        $establishments = $this->establishments->ofCompany($company->getId());
        if ([] === $establishments) {
            $establishments = [Establishment::create($company, $preset->establishment->defaultCode, mb_substr($company->getName(), 0, 120), true, $now)];
            $this->establishments->save($establishments[0]);
            $copied[] = \sprintf('establishments of %s', $company->getName());
        }

        if ([] === $this->series->ofCompany($company->getId())) {
            $default = array_find($establishments, static fn (Establishment $e): bool => $e->isDefault()) ?? $establishments[0];
            foreach ($preset->numbering as $documentType => $numbering) {
                $this->series->save(NumberingSeries::create($company, $default, $documentType, new NumberFormat($numbering->format), ResetPeriod::from($numbering->reset), true, $now));
            }
            $copied[] = \sprintf('numbering series of %s', $company->getName());
        }

        return $copied;
    }
}

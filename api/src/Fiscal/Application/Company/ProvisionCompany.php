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
use Psr\Clock\ClockInterface;

/**
 * A company copies its fiscal preset's tax components and units into its own rows, named in its language, and edits
 * that copy from then on (docs/SPEC.md § 3 Fiscal presets). Idempotent: a company that already has taxes, or units,
 * keeps them untouched.
 */
final readonly class ProvisionCompany
{
    public function __construct(
        private FiscalPresets $presets,
        private TaxComponentRepository $components,
        private UnitRepository $units,
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

        return $copied;
    }
}

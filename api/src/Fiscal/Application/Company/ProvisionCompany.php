<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Company;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Application\Preset\FiscalPreset;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\PresetTaxComponent;
use App\Fiscal\Application\Preset\PresetUnit;
use App\Fiscal\Application\Preset\UnknownFiscalPreset;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use App\Tenancy\Domain\Model\NumberingSeries;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use App\Tenancy\Domain\Repository\NumberingSeriesRepository;
use App\Tenancy\Domain\ValueObject\NumberFormat;
use App\Tenancy\Domain\ValueObject\ResetPeriod;
use Psr\Clock\ClockInterface;

/**
 * A company copies its fiscal preset's tax components and units into its own rows, named in its language, and edits
 * that copy from then on (docs/SPEC.md § 3 Fiscal presets). It also starts with one default establishment, coded the
 * preset's way and named after the company, and one default numbering series per document type the preset numbers, on
 * that establishment. Idempotent: a company that already has taxes, units or establishments keeps them untouched, and
 * one that has series gains only those of a document type it has none of.
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
        $now = $this->clock->now();
        $copied = [];

        if ([] === $this->components->ofCompany($company->getId())) {
            $scale = $this->scales->of($company->getCurrency());
            foreach ($preset->taxComponents as $component) {
                $this->components->save(TaxComponent::create(
                    $company,
                    $component->code,
                    self::nameIn($component->names, $company, $preset),
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
                    self::otherNames($component->names, $company, $preset),
                ));
            }
            $copied[] = \sprintf('tax components of %s', $company->getName());
        }

        if ([] === $this->units->ofCompany($company->getId())) {
            foreach ($preset->units as $unit) {
                $this->units->save(Unit::create($company, $unit->code, self::nameIn($unit->names, $company, $preset), $unit->decimals, $unit->sortOrder, $now, self::otherNames($unit->names, $company, $preset)));
            }
            $copied[] = \sprintf('units of %s', $company->getName());
        }

        // Units and taxes from before they were named by language are given their preset's names once, by code; the
        // name the company may have changed since is its own, and the language it was taken in keeps printing it.
        $presetUnits = array_column(array_map(static fn (PresetUnit $unit): array => [$unit->code, $unit->names], $preset->units), 1, 0);
        $named = array_filter($this->units->ofCompany($company->getId()), static fn (Unit $unit): bool => $unit->giveLanguageNames(self::otherNames($presetUnits[$unit->getCode()] ?? [], $company, $preset)));
        array_walk($named, fn (Unit $unit) => $this->units->save($unit));
        if ([] !== $named) {
            $copied[] = \sprintf('unit names by language of %s', $company->getName());
        }
        $presetTaxes = array_column(array_map(static fn (PresetTaxComponent $tax): array => [$tax->code, $tax->names], $preset->taxComponents), 1, 0);
        $named = array_filter($this->components->ofCompany($company->getId()), static fn (TaxComponent $tax): bool => $tax->giveLanguageNames(self::otherNames($presetTaxes[$tax->getCode()] ?? [], $company, $preset)));
        array_walk($named, fn (TaxComponent $tax) => $this->components->save($tax));
        if ([] !== $named) {
            $copied[] = \sprintf('tax names by language of %s', $company->getName());
        }

        $establishments = $this->establishments->ofCompany($company->getId());
        if ([] === $establishments) {
            $establishments = [Establishment::create($company, $preset->establishment->defaultCode, mb_substr($company->getName(), 0, 120), true, $now)];
            $this->establishments->save($establishments[0]);
            $copied[] = \sprintf('establishments of %s', $company->getName());
        }

        // Per document type, so a type the preset numbers since the company was created (the quote) reaches it on the
        // next run, while every series it already has is left as it is.
        $numbered = array_map(static fn (NumberingSeries $series): string => $series->getDocumentType(), $this->series->ofCompany($company->getId()));
        $missing = array_diff_key($preset->numbering, array_flip($numbered));
        if ([] !== $missing) {
            $default = array_find($establishments, static fn (Establishment $e): bool => $e->isDefault()) ?? $establishments[0];
            foreach ($missing as $documentType => $numbering) {
                $this->series->save(NumberingSeries::create($company, $default, $documentType, new NumberFormat($numbering->format), ResetPeriod::from($numbering->reset), true, $now));
            }
            $copied[] = \sprintf('numbering series of %s', $company->getName());
        }

        return $copied;
    }

    /**
     * A preset names everything in each language documents print in; a company printing in none of its own is given
     * the names in its country's first.
     *
     * @param array<string, string> $names
     */
    private static function nameIn(array $names, Company $company, FiscalPreset $preset): string
    {
        return $names[self::nameLanguage($names, $company, $preset)] ?? throw new \LogicException('A preset names everything in its document languages.');
    }

    /**
     * The preset's names in every other language documents print in: the name stands for the language it was taken in.
     *
     * @param array<string, string> $names
     *
     * @return array<string, string>
     */
    private static function otherNames(array $names, Company $company, FiscalPreset $preset): array
    {
        unset($names[self::nameLanguage($names, $company, $preset)]);

        return $names;
    }

    /** @param array<string, string> $names */
    private static function nameLanguage(array $names, Company $company, FiscalPreset $preset): string
    {
        return isset($names[$company->getLocale()]) ? $company->getLocale() : ($preset->documentLanguages[0] ?? '');
    }
}

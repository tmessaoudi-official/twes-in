<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Preset\UnknownFiscalPreset;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxFamily;
use App\Fiscal\Domain\Unit;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\NumberingSeries;
use App\Tenancy\Domain\ResetPeriod;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ProvisionCompanyTest extends TestCase
{
    private InMemoryTaxComponents $components;
    private InMemoryUnits $units;
    private InMemoryEstablishments $establishments;
    private InMemoryNumberingSeries $series;
    private ProvisionCompany $provision;

    protected function setUp(): void
    {
        $this->components = new InMemoryTaxComponents();
        $this->units = new InMemoryUnits();
        $this->establishments = new InMemoryEstablishments();
        $this->series = new InMemoryNumberingSeries();
        $this->provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->components, $this->units, $this->establishments, $this->series, ShippedFiscalPresets::scales(), new MockClock('2026-09-13 10:00:00'));
    }

    public function testATunisianCompanyGetsItsPresetsTaxesAndUnits(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');

        $copied = $this->provision->handle($company);

        self::assertSame(['tax components of Acme', 'units of Acme', 'establishments of Acme', 'numbering series of Acme'], $copied);
        $codes = array_map(static fn (TaxComponent $c) => $c->getCode(), $this->components->ofCompany($company->getId()));
        self::assertSame(['TVA19', 'TVA13', 'TVA7', 'FODEC', 'TIMBRE', 'RS1'], $codes);
        self::assertSame(['C62', 'H87', 'HUR', 'DAY', 'KGM', 'MTR', 'MTK', 'LTR'], array_map(static fn (Unit $u) => $u->getCode(), $this->units->ofCompany($company->getId())));
    }

    public function testTheCopiedValuesAreThePresetsWithTheirKindsAndFlags(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->provision->handle($company);

        $fodec = $this->components->ofCodeInCompany('FODEC', $company->getId());
        self::assertNotNull($fodec);
        self::assertSame(TaxFamily::Levy, $fodec->getFamily());
        self::assertSame('1.000', $fodec->getRate());
        self::assertTrue($fodec->entersVatBase());
        $stamp = $this->components->ofCodeInCompany('TIMBRE', $company->getId());
        self::assertNotNull($stamp);
        self::assertSame('1.000', $stamp->getAmount());
        self::assertNull($stamp->getRate());
        $withholding = $this->components->ofCodeInCompany('RS1', $company->getId());
        self::assertSame('1000.000', $withholding?->getThreshold());
        self::assertTrue($this->components->ofCodeInCompany('TVA19', $company->getId())?->isDefault());
    }

    public function testNamesAreCopiedInTheCompanysLanguage(): void
    {
        $french = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $english = new Company('Globex', 'TN', 'TND', 'en', 'Africa/Tunis');
        $this->provision->handle($french);
        $this->provision->handle($english);

        $frenchHour = $this->units->ofCodeInCompany('HUR', $french->getId())?->getName();
        $englishHour = $this->units->ofCodeInCompany('HUR', $english->getId())?->getName();
        self::assertNotNull($frenchHour);
        self::assertNotNull($englishHour);
        self::assertNotSame($frenchHour, $englishHour);
    }

    public function testAFrenchCompanyGetsTheFrenchRates(): void
    {
        $company = new Company('Dupont', 'FR', 'EUR', 'fr', 'Europe/Paris');

        $this->provision->handle($company);

        self::assertSame('5.500', $this->components->ofCodeInCompany('TVA5_5', $company->getId())?->getRate());
        self::assertCount(4, $this->components->ofCompany($company->getId()));
    }

    public function testASecondRunCopiesNothingAndKeepsTheCompanysEdits(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->provision->handle($company);
        $stamp = $this->components->ofCodeInCompany('TIMBRE', $company->getId());
        self::assertNotNull($stamp);
        $stamp->revise('Droit de timbre', null, '1.500', null, false, true, true, null, 50, 3, new \DateTimeImmutable());

        self::assertSame([], $this->provision->handle($company));
        self::assertCount(6, $this->components->ofCompany($company->getId()));
        self::assertSame('1.500', $this->components->ofCodeInCompany('TIMBRE', $company->getId())?->getAmount());
    }

    public function testACompanyThatHasUnitsButNoTaxesGetsOnlyTheTaxes(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->units->save(Unit::create($company, 'C62', 'Pièce', 0, 0, new \DateTimeImmutable()));

        self::assertSame(['tax components of Acme', 'establishments of Acme', 'numbering series of Acme'], $this->provision->handle($company));
        self::assertCount(1, $this->units->ofCompany($company->getId()));
    }

    public function testACountryWithNoPresetIsRefusedBeforeAnythingIsWritten(): void
    {
        $company = new Company('Weiss', 'DE', 'EUR', 'de', 'Europe/Berlin');

        try {
            $this->provision->handle($company);
            self::fail('expected UnknownFiscalPreset');
        } catch (UnknownFiscalPreset) {
        }

        self::assertSame([], $this->components->components);
        self::assertSame([], $this->units->units);
        self::assertSame([], $this->establishments->establishments);
        self::assertSame([], $this->series->series);
    }

    public function testACompanyGetsOneDefaultEstablishmentCodedByItsPreset(): void
    {
        $tunisian = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $french = new Company('Dupont', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $this->provision->handle($tunisian);
        $this->provision->handle($french);

        $establishments = $this->establishments->ofCompany($tunisian->getId());
        self::assertCount(1, $establishments);
        self::assertSame('000', $establishments[0]->getCode());
        self::assertSame('Acme', $establishments[0]->getName());
        self::assertTrue($establishments[0]->isDefault());
        self::assertSame('00001', $this->establishments->ofCompany($french->getId())[0]->getCode());
    }

    public function testTheDefaultEstablishmentNumbersEachDocumentTypeThePresetsWay(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->provision->handle($company);

        $series = $this->series->ofCompany($company->getId());
        self::assertSame(
            ['credit_note' => 'AV-{YYYY}-{SEQ:5}', 'delivery_note' => 'BL-{YYYY}-{SEQ:5}', 'invoice' => 'FAC-{YYYY}-{SEQ:5}'],
            array_combine(array_map(static fn (NumberingSeries $s) => $s->getDocumentType(), $series), array_map(static fn (NumberingSeries $s) => $s->getFormat(), $series)),
        );
        foreach ($series as $each) {
            self::assertTrue($each->isDefault());
            self::assertSame(1, $each->getNextNumber());
            self::assertSame(ResetPeriod::Yearly, $each->getResetPeriod());
            self::assertSame('000', $each->getEstablishment()->getCode());
        }
    }

    public function testAnEstablishmentAndSeriesAlreadyThereAreLeftAlone(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->provision->handle($company);

        self::assertSame([], $this->provision->handle($company));
        self::assertCount(1, $this->establishments->ofCompany($company->getId()));
        self::assertCount(3, $this->series->ofCompany($company->getId()));
    }
}

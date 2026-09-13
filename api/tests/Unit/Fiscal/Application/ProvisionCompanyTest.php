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
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ProvisionCompanyTest extends TestCase
{
    private InMemoryTaxComponents $components;
    private InMemoryUnits $units;
    private ProvisionCompany $provision;

    protected function setUp(): void
    {
        $this->components = new InMemoryTaxComponents();
        $this->units = new InMemoryUnits();
        $this->provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->components, $this->units, ShippedFiscalPresets::scales(), new MockClock('2026-09-13 10:00:00'));
    }

    public function testATunisianCompanyGetsItsPresetsTaxesAndUnits(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');

        $copied = $this->provision->handle($company);

        self::assertSame(['tax components of Acme', 'units of Acme'], $copied);
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

        self::assertSame(['tax components of Acme'], $this->provision->handle($company));
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
    }
}

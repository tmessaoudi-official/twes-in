<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Application;

use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxFamily;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;

/**
 * A document leaves out what its customer's regime does not charge AND what its company's own regime does not charge
 * (docs/SPEC.md § 7, 2026-09-20 04:15: franchise brings none of the VAT; audit EXT-03).
 */
final class ExcludedTaxFamiliesTest extends TestCase
{
    private ExcludedTaxFamilies $excluded;

    protected function setUp(): void
    {
        $this->excluded = new ExcludedTaxFamilies(ShippedFiscalPresets::presets());
    }

    public function testAStandardCompanyLeavesOutWhatItsCustomersRegimeDoes(): void
    {
        $company = $this->company('standard');

        self::assertSame([], $this->excluded->of($company, $this->regime('standard', [])));
        self::assertSame([TaxFamily::Vat], $this->excluded->of($company, $this->regime('exempt', [TaxFamily::Vat])));
        self::assertNull($this->excluded->regimeLeavingOut($company, $this->regime('standard', []), TaxFamily::Vat));
    }

    public function testACompanyOnFranchiseLeavesVatOutForEveryCustomerAndSaysWhichRegimeDid(): void
    {
        $company = $this->company('franchise');

        self::assertSame([TaxFamily::Vat], $this->excluded->of($company, $this->regime('standard', [])));
        self::assertSame('franchise', $this->excluded->regimeLeavingOut($company, $this->regime('standard', []), TaxFamily::Vat));
        self::assertSame([TaxFamily::Vat, TaxFamily::Stamp], $this->excluded->of($company, $this->regime('unstamped', [TaxFamily::Vat, TaxFamily::Stamp])), 'each family once');
        self::assertSame('exempt', $this->excluded->regimeLeavingOut($company, $this->regime('exempt', [TaxFamily::Vat]), TaxFamily::Vat), 'the customer\'s own regime is named first');
        self::assertSame('fiscal.mention.fr.franchise', $this->excluded->companyRegime($company)?->mentionKey);
    }

    public function testACompanyWhosePresetHasNoSuchRegimeLeavesNothingOutOfItsOwn(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');

        self::assertSame([], $this->excluded->of($company, $this->regime('standard', [])));
        self::assertNull($this->excluded->companyRegime($company)?->mentionKey);
    }

    private function company(string $vatRegime): Company
    {
        $company = new Company('Atelier', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $company->reviseProfile(new CompanyProfile(vatRegime: $vatRegime));

        return $company;
    }

    /** @param list<TaxFamily> $families */
    private function regime(string $code, array $families): CustomerTaxRegime
    {
        return new CustomerTaxRegime('FR', $code, 'fiscal.regime.'.$code, $families, null, 0, new \DateTimeImmutable('2026-09-25'));
    }
}

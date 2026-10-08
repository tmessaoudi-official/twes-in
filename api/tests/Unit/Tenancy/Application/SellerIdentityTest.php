<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Tenancy\Application\Company\SellerIdentity;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use App\Tenancy\Domain\Establishment;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;

/** What an invoice must say of its seller, by the company's own preset. */
final class SellerIdentityTest extends TestCase
{
    public function testAnEmptyProfileLacksTheLegalNameTheAddressAndTheMatricule(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');

        self::assertSame(['legalName', 'addressLine1', 'city', 'identifiers.matricule_fiscal'], $this->missing($company));
    }

    public function testATunisianSellerWithItsNameAddressAndMatriculeLacksNothing(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $company->reviseProfile(new CompanyProfile(legalName: 'Acme SARL', identifiers: ['matricule_fiscal' => '1234567A/B/M/000'], addressLine1: 'Rue de Marseille', city: 'Tunis'));

        self::assertSame([], $this->missing($company));
    }

    public function testAMatriculeOfTheWrongShapeIsNamedAsMissing(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $company->reviseProfile(new CompanyProfile(legalName: 'Acme SARL', identifiers: ['matricule_fiscal' => '1234567'], addressLine1: 'Rue de Marseille', city: 'Tunis'));

        self::assertSame(['identifiers.matricule_fiscal'], $this->missing($company));
    }

    public function testAFrenchSellerNeedsItsSirenAndSiret(): void
    {
        $company = new Company('Durand', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $company->reviseProfile(new CompanyProfile(legalName: 'Durand SARL', identifiers: ['siren' => '732829320'], addressLine1: '12 rue des Forges', postalCode: '69007', city: 'Lyon'));

        self::assertSame(['identifiers.siret'], $this->missing($company));
    }

    public function testTheIssuingEstablishmentsAddressStandsForTheCompanysWhenItHasOne(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $company->reviseProfile(new CompanyProfile(legalName: 'Acme SARL', identifiers: ['matricule_fiscal' => '1234567A/B/M/000']));
        $now = new \DateTimeImmutable('2026-10-08 09:00:00');
        $shop = Establishment::create($company, 'SFX', 'Sfax', false, $now);

        self::assertSame(['addressLine1', 'city'], $this->missing($company, $shop), 'an establishment without its own address prints the company\'s');

        $shop->revise('SFX', 'Sfax', 'Route de Gabès', null, '3000', 'Sfax', null, null, $now);
        self::assertSame([], $this->missing($company, $shop));
        self::assertSame(['addressLine1', 'city'], $this->missing($company), 'the company itself still has none');
    }

    /** @return list<string> */
    private function missing(Company $company, ?Establishment $establishment = null): array
    {
        return SellerIdentity::missing($company, ShippedFiscalPresets::presets()->get($company->getFiscalPreset()), $establishment);
    }
}

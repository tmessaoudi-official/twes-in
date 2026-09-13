<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Domain;

use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Company::class)]
#[CoversClass(CompanyProfile::class)]
final class CompanyProfileTest extends TestCase
{
    public function testAProfileKeepsWhatWasTypedWithoutItsStraySpacesAndForgetsWhatWasLeftEmpty(): void
    {
        $profile = new CompanyProfile(
            legalName: '  Acme SARL ',
            legalForm: '',
            identifiers: ['vat_number' => '  ', 'matricule_fiscal' => ' 1234567A/B/M/000 '],
            city: ' Tunis',
            invoiceFooterText: '   ',
        );

        self::assertSame('Acme SARL', $profile->legalName);
        self::assertNull($profile->legalForm);
        self::assertSame(['matricule_fiscal' => '1234567A/B/M/000'], $profile->identifiers);
        self::assertSame('Tunis', $profile->city);
        self::assertNull($profile->invoiceFooterText);
    }

    public function testBankingCodesAreKeptInTheirCanonicalForm(): void
    {
        $profile = new CompanyProfile(iban: 'tn59 1000 6035 1835 9847 8831', bic: ' biattntt ');

        self::assertSame('TN5910006035183598478831', $profile->iban);
        self::assertSame('BIATTNTT', $profile->bic);
    }

    public function testIdentifiersAreKeptInKeyOrderSoTheSameProfileComparesEqual(): void
    {
        $one = new CompanyProfile(identifiers: ['siret' => '12345678900011', 'siren' => '123456789']);
        $two = new CompanyProfile(identifiers: ['siren' => '123456789', 'siret' => '12345678900011']);

        self::assertSame(['siren', 'siret'], array_keys($one->identifiers));
        self::assertTrue($one->equals($two));
    }

    public function testANewCompanyHasAnEmptyProfileUnderTheStandardRegime(): void
    {
        $profile = self::company()->getProfile();

        self::assertTrue($profile->equals(new CompanyProfile()));
        self::assertSame('standard', $profile->vatRegime);
        self::assertSame([], $profile->identifiers);
        self::assertNull($profile->legalName);
    }

    public function testRevisingTheProfileRecordsTheChangeOnce(): void
    {
        $company = self::company();
        $later = new \DateTimeImmutable('+1 hour');
        $profile = new CompanyProfile(legalName: 'Acme SARL', email: 'billing@acme.tn', latePenaltyText: 'Pénalités de retard.');

        self::assertTrue($company->reviseProfile($profile, $later));
        self::assertTrue($company->getProfile()->equals($profile));
        self::assertEquals($later, $company->getUpdatedAt());

        self::assertFalse($company->reviseProfile(new CompanyProfile(legalName: 'Acme SARL ', email: 'billing@acme.tn', latePenaltyText: 'Pénalités de retard.'), new \DateTimeImmutable('+2 hours')));
        self::assertEquals($later, $company->getUpdatedAt());
    }

    private static function company(): Company
    {
        return new Company('Demo', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }
}

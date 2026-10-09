<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Domain;

use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\SellerSnapshot;
use PHPUnit\Framework\TestCase;

/** The seller as an issued document prints it, frozen at issue: a company that moves or renames later changes none. */
final class SellerSnapshotTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-27 09:00:00');
    }

    public function testItKeepsWhatTheDocumentPrintsWithTheEstablishmentsAddressAndContactsFirst(): void
    {
        $company = $this->company();
        $shop = Establishment::create($company, 'SFX', 'Sfax', false, $this->now);
        $shop->revise('SFX', 'Sfax', '4 avenue Bourguiba', 'Bloc B', '3000', 'Sfax', '+216 74 000 000', null, $this->now);

        $seller = SellerSnapshot::of($company, $shop);

        self::assertSame(['Carthage Conseil SARL', 'SARL', ['matricule_fiscal' => '1234567/A/M/000']], [$seller->name, $seller->legalForm, $seller->identifiers]);
        self::assertSame(['4 avenue Bourguiba', 'Bloc B', '3000', 'Sfax', 'TN'], $seller->address->parts());
        // The establishment has a phone and no e-mail: each falls back on its own.
        self::assertSame(['+216 74 000 000', 'contact@carthage.tn'], [$seller->phone, $seller->email]);
        self::assertSame(['TN5910006035183598478831', 'BSTUTNTT', 'TND', 'exempt'], [$seller->iban, $seller->bic, $seller->currency, $seller->vatRegime]);
    }

    public function testAnEstablishmentWithoutAnAddressPrintsTheCompanysAndATradeNameStandsInForAMissingLegalName(): void
    {
        $company = new Company('Atelier Durand', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $company->reviseProfile(new CompanyProfile(addressLine1: '12 rue des Forges', postalCode: '69002', city: 'Lyon'));
        $seller = SellerSnapshot::of($company, Establishment::create($company, 'SIEGE', 'Siège', true, $this->now));

        self::assertSame(['Atelier Durand', null, 'EUR'], [$seller->name, $seller->legalForm, $seller->currency]);
        self::assertSame(['12 rue des Forges', null, '69002', 'Lyon', 'FR'], $seller->address->parts());
    }

    public function testItIsStoredAndReadBackUnchanged(): void
    {
        $company = $this->company();
        $seller = SellerSnapshot::of($company, Establishment::create($company, 'SIEGE', 'Siège', true, $this->now));

        $stored = json_decode(json_encode($seller->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);

        self::assertEquals($seller, SellerSnapshot::fromArray($stored));
    }

    /** A legal name that already ends with its form prints it once: « Carthage Conseil SARL », never « … SARL SARL ». */
    public function testThePrintedNameCarriesTheLegalFormOnce(): void
    {
        $seller = SellerSnapshot::of($this->company(), Establishment::create($this->company(), 'TUN', 'Tunis', true, $this->now));
        $named = static fn (string $name, ?string $form): string => SellerSnapshot::fromArray(['name' => $name, 'legalForm' => $form] + $seller->toArray())->printedName();

        self::assertSame('Carthage Conseil SARL', $seller->printedName());
        self::assertSame('Carthage Conseil SARL', $named('Carthage Conseil', 'SARL'));
        self::assertSame('Atelier Mercier sas', $named('Atelier Mercier sas', 'SAS'));
        self::assertSame('Atelier Mercier', $named('Atelier Mercier', null));
        // The form is a word of its own: a name that merely ends with its letters still takes it.
        self::assertSame('Le Vasas SAS', $named('Le Vasas', 'SAS'));
        self::assertSame('Mercier SAS', $named('Mercier', ' SAS '));
    }

    private function company(): Company
    {
        $company = new Company('Carthage Conseil', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $company->reviseProfile(new CompanyProfile(
            legalName: 'Carthage Conseil SARL',
            legalForm: 'SARL',
            identifiers: ['matricule_fiscal' => '1234567/A/M/000'],
            addressLine1: '10 rue de Marseille',
            postalCode: '1000',
            city: 'Tunis',
            email: 'contact@carthage.tn',
            phone: '+216 71 000 000',
            iban: 'TN59 1000 6035 1835 9847 8831',
            bic: 'BSTUTNTT',
            vatRegime: 'exempt',
        ));

        return $company;
    }
}

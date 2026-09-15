<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Vendors\Application;

use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Application\VendorInput;
use App\Module\Vendors\Application\VendorNotFound;
use App\Module\Vendors\Application\VendorNumberTaken;
use App\Module\Vendors\Domain\InvalidVendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryVendors;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ManageVendorsTest extends TestCase
{
    private InMemoryAuditTrail $audit;
    private ManageVendors $manage;
    private Company $company;
    private Company $globex;

    protected function setUp(): void
    {
        $this->audit = new InMemoryAuditTrail();
        $this->manage = new ManageVendors(new InMemoryVendors(), ShippedFiscalPresets::presets(), $this->audit, new MockClock('2026-09-15 09:00:00'));
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }

    public function testADomesticVendorNeedNotCarryThePresetsRegistrationNumbers(): void
    {
        $vendor = $this->manage->create($this->company, $this->input('FRN-0001', new VendorProfile('Sotumag', address: new PostalAddress(city: 'Tunis', countryCode: 'TN'))), null);

        self::assertSame('FRN-0001', $vendor->getNumber());
        self::assertSame([], $vendor->getProfile()->identifiers);
        self::assertSame([ManageVendors::ENTITY_TYPE, ManageVendors::CREATED, []], [$this->audit->entries[0]->entityType, $this->audit->entries[0]->action, $this->audit->entries[0]->changes]);
    }

    public function testTheRegistrationNumbersAVendorCarriesAreThePresetsInTheirShape(): void
    {
        $this->manage->create($this->company, $this->input('FRN-0001', new VendorProfile('Sotumag', identifiers: ['matricule_fiscal' => '1234567A/B/M/000'])), null);

        foreach ([
            'identifiers.matricule_fiscal' => ['matricule_fiscal' => '1234'],
            'identifiers.siret' => ['siret' => '12345678900011'],
        ] as $field => $identifiers) {
            try {
                $this->manage->create($this->company, $this->input('FRN-0002', new VendorProfile('Autre', identifiers: $identifiers)), null);
                self::fail("$field was accepted");
            } catch (InvalidVendor $refused) {
                self::assertSame($field, $refused->field);
            }
        }
    }

    public function testANumberIsTheCompanysToChooseOnce(): void
    {
        $first = $this->manage->create($this->company, $this->input('FRN-0001'), null);
        $second = $this->manage->create($this->company, $this->input('FRN-0002'), null);
        $this->manage->create($this->globex, $this->input('FRN-0001'), null);

        $this->manage->revise($this->company, $first->getId(), $this->input(' FRN-0001 '), null);
        $this->expectException(VendorNumberTaken::class);
        try {
            $this->manage->create($this->company, $this->input('FRN-0001 '), null);
        } finally {
            try {
                $this->manage->revise($this->company, $second->getId(), $this->input('FRN-0001'), null);
                self::fail('a revision took another vendor’s number');
            } catch (VendorNumberTaken) {
            }
        }
    }

    public function testANumberOutOfShapeIsRefused(): void
    {
        $this->expectExceptionObject(new InvalidVendor('number', '"-FRN" is not a vendor number: 1 to 32 letters, digits, dots, dashes, slashes or underscores.'));

        $this->manage->create($this->company, $this->input('-FRN'), null);
    }

    public function testARevisionIsAuditedOnlyWhenItChangesSomething(): void
    {
        $vendor = $this->manage->create($this->company, $this->input('FRN-0001'), null);

        $this->manage->revise($this->company, $vendor->getId(), $this->input('FRN-0001'), null);
        self::assertCount(1, $this->audit->entries);

        $this->manage->revise($this->company, $vendor->getId(), $this->input('FRN-0009', new VendorProfile('Sotumag', iban: 'TN5910006035183598478831'), false), null);
        self::assertSame([ManageVendors::REVISED, ['fields' => ['number', 'iban', 'isActive']]], [$this->audit->entries[1]->action, $this->audit->entries[1]->changes]);
        self::assertFalse($vendor->isActive());
    }

    public function testAnotherCompanysVendorIsNotFound(): void
    {
        $theirs = $this->manage->create($this->globex, $this->input('FRN-0001'), null);

        self::assertSame([], $this->manage->list($this->company));
        $this->expectException(VendorNotFound::class);
        $this->manage->revise($this->company, $theirs->getId(), $this->input('FRN-0001'), null);
    }

    private function input(string $number, ?VendorProfile $profile = null, bool $isActive = true): VendorInput
    {
        return new VendorInput($number, $profile ?? new VendorProfile('Sotumag'), $isActive);
    }
}

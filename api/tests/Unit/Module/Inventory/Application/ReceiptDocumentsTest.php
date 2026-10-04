<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Application;

use App\Module\Inventory\Application\ReceiptDocuments;
use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Module\Vendors\Infrastructure\Module\VendorsModule;
use App\ModuleRegistry\Application\ModuleCatalog;
use App\ModuleRegistry\Application\ModuleStates;
use App\ModuleRegistry\Domain\ModuleState;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemoryModuleStates;
use App\Tests\Support\InMemoryVendors;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ReceiptDocumentsTest extends TestCase
{
    private InMemoryModuleStates $states;
    private Company $company;
    private Vendor $vendor;
    private ReceiptDocuments $documents;

    protected function setUp(): void
    {
        $now = new \DateTimeImmutable('2026-09-15 09:00:00');
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $vendors = new InMemoryVendors();
        $this->vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Sotumag'), $now);
        $vendors->save($this->vendor);
        $vendors->save(Vendor::create(new Company('Other', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'FRN-0002', new VendorProfile('Ailleurs'), $now));
        $this->states = new InMemoryModuleStates();
        $this->documents = new ReceiptDocuments($vendors, new ModuleStates(new ModuleCatalog([new VendorsModule()]), $this->states));
    }

    public function testNothingNamedIsNoDocument(): void
    {
        self::assertNull($this->documents->named($this->company, null, null, null));
        self::assertNull($this->documents->named($this->company, null, '  ', ''));
    }

    public function testAVendorOfTheCompanyAReferenceAndADayMakeTheDocument(): void
    {
        $document = $this->documents->named($this->company, $this->vendor->getId()->toRfc4122(), ' BL-9 ', '2026-09-12');

        self::assertSame([$this->vendor, 'BL-9', '2026-09-12'], [$document?->vendor, $document?->supplierReference, $document?->receivedOn?->format('Y-m-d')]);
    }

    public function testAVendorTheCompanyDoesNotHaveIsRefusedNamingTheField(): void
    {
        $this->refused('vendorId', fn () => $this->documents->named($this->company, Uuid::v7()->toRfc4122(), null, null));
    }

    public function testNoVendorIsNamedWhileVendorsIsSwitchedOff(): void
    {
        $this->states->save(ModuleState::of($this->company, 'vendors', false, new \DateTimeImmutable()));

        $this->refused('vendorId', fn () => $this->documents->named($this->company, $this->vendor->getId()->toRfc4122(), null, null));
        self::assertNotNull($this->documents->named($this->company, null, 'BL-1', null), 'a reference and a day need no vendor');
    }

    public function testADayThatRollsOverIsRefusedRatherThanReadAsAnother(): void
    {
        $this->refused('receivedOn', fn () => $this->documents->named($this->company, null, null, '2026-13-45'));
        $this->refused('receivedOn', fn () => $this->documents->named($this->company, null, null, 'yesterday'));
    }

    private function refused(string $field, callable $call): void
    {
        try {
            $call();
            self::fail('the document was accepted');
        } catch (InvalidStockMovement $refused) {
            self::assertSame($field, $refused->field);
        }
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Tenancy\Application\Company\CompanyNameTaken;
use App\Tenancy\Application\Company\CreateCompany;
use App\Tenancy\Application\Company\NewCompany;
use App\Tenancy\Application\Company\NoFiscalPreset;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCompanies;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CreateCompany::class)]
final class CreateCompanyTest extends TestCase
{
    private InMemoryCompanies $companies;
    private InMemoryAuditTrail $audit;
    private InMemoryTaxComponents $components;
    private InMemoryUnits $units;
    private CreateCompany $create;

    protected function setUp(): void
    {
        $this->companies = new InMemoryCompanies();
        $transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($transactions);
        $this->components = new InMemoryTaxComponents();
        $this->units = new InMemoryUnits();
        $clock = new MockClock('2026-09-09 10:00:00');
        $presets = ShippedFiscalPresets::presets();
        $provision = new ProvisionCompany($presets, $this->components, $this->units, new \App\Tests\Support\InMemoryEstablishments(), new \App\Tests\Support\InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $clock);
        $this->create = new CreateCompany($this->companies, $presets, $provision, $this->audit, $clock, $transactions);
    }

    public function testTheCompanyWaitsForItsFirstOwner(): void
    {
        $company = $this->create->handle(self::request(), Uuid::v7());

        self::assertSame(Company::STATUS_PENDING, $company->getStatus());
        self::assertSame('Acme', $company->getName());
        self::assertSame([$company], $this->companies->companies);
    }

    public function testTheCountryAndCurrencyAreStoredUpperCase(): void
    {
        $company = $this->create->handle(new NewCompany('Acme', 'tn', 'tnd', 'fr', 'Africa/Tunis'), null);

        self::assertSame('TN', $company->getCountryCode());
        self::assertSame('TND', $company->getCurrency());
        self::assertSame('TN', $company->getFiscalPreset());
    }

    public function testTheCompanyOpensWithItsPresetsTaxesAndUnits(): void
    {
        $company = $this->create->handle(self::request(), null);

        self::assertCount(6, $this->components->ofCompany($company->getId()));
        self::assertCount(8, $this->units->ofCompany($company->getId()));
    }

    public function testACountryWithNoFiscalPresetIsRefusedBeforeAnythingIsWritten(): void
    {
        try {
            $this->create->handle(new NewCompany('Weiss', 'DE', 'EUR', 'de', 'Europe/Berlin'), null);
            self::fail('expected NoFiscalPreset');
        } catch (NoFiscalPreset) {
        }

        self::assertSame([], $this->companies->companies);
        self::assertSame([], $this->components->components);
        self::assertSame([], $this->audit->entries);
    }

    public function testTwoCompaniesCannotShareAName(): void
    {
        $this->create->handle(self::request(), null);

        $this->expectException(CompanyNameTaken::class);
        $this->create->handle(self::request(), null);
    }

    public function testTheRefusedSecondCompanyIsNotStored(): void
    {
        $this->create->handle(self::request(), null);

        try {
            $this->create->handle(self::request(), null);
        } catch (CompanyNameTaken) {
        }

        self::assertCount(1, $this->companies->companies);
    }

    public function testCreationIsAudited(): void
    {
        $actor = Uuid::v7();
        $company = $this->create->handle(self::request(), $actor);

        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame('company', $entry->entityType);
        self::assertSame('company.created', $entry->action);
        self::assertNotNull($entry->entityId);
        self::assertTrue($company->getId()->equals($entry->entityId));
        self::assertSame($actor, $entry->actorUserId);
        self::assertSame('TN', $entry->changes['fiscal_preset']);
    }

    private static function request(): NewCompany
    {
        return new NewCompany('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }
}

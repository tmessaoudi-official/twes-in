<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Tenancy\Application\Numbering\ManageNumberingSeries;
use App\Tenancy\Application\Numbering\NumberingChanges;
use App\Tenancy\Application\Numbering\NumberingSeriesNotFound;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\InvalidNumbering;
use App\Tenancy\Domain\NumberingSeries;
use App\Tenancy\Domain\ResetPeriod;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ManageNumberingSeriesTest extends TestCase
{
    private InMemoryNumberingSeries $series;
    private InMemoryAuditTrail $audit;
    private ManageNumberingSeries $manage;
    private ProvisionCompany $provision;
    private Company $company;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-09-13 10:00:00');
        $this->series = new InMemoryNumberingSeries();
        $this->audit = new InMemoryAuditTrail();
        $this->provision = new ProvisionCompany(ShippedFiscalPresets::presets(), new InMemoryTaxComponents(), new InMemoryUnits(), new InMemoryEstablishments(), $this->series, ShippedFiscalPresets::scales(), $clock);
        $this->manage = new ManageNumberingSeries($this->series, $this->audit, $clock);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->provision->handle($this->company);
    }

    public function testARevisionIsAuditedOnlyWhenItChangesSomething(): void
    {
        $invoices = $this->invoices($this->company);

        $this->manage->revise($this->company, $invoices->getId(), new NumberingChanges($invoices->getFormat(), 1, 'yearly'), null);
        $this->manage->revise($this->company, $invoices->getId(), new NumberingChanges('F{YY}{MM}-{SEQ:4}', 120, 'monthly'), null);

        self::assertSame('F{YY}{MM}-{SEQ:4}', $invoices->getFormat());
        self::assertSame(120, $invoices->getNextNumber());
        self::assertSame(ResetPeriod::Monthly, $invoices->getResetPeriod());
        self::assertCount(1, $this->audit->entries);
        self::assertSame('numbering_series.revised', $this->audit->entries[0]->action);
        self::assertSame(['format' => 'F{YY}{MM}-{SEQ:4}', 'next_number' => 120, 'reset_period' => 'monthly'], $this->audit->entries[0]->changes);
    }

    public function testAResetPeriodThatDoesNotExistIsRefused(): void
    {
        $invoices = $this->invoices($this->company);

        try {
            $this->manage->revise($this->company, $invoices->getId(), new NumberingChanges('F-{SEQ}', 1, 'weekly'), null);
            self::fail('A weekly reset was accepted.');
        } catch (InvalidNumbering $refused) {
            self::assertSame('resetPeriod', $refused->field);
        }
        self::assertSame('FAC-{YYYY}-{SEQ:5}', $invoices->getFormat());
    }

    public function testAnotherCompanysSeriesIsNotFound(): void
    {
        $globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->provision->handle($globex);

        $this->expectException(NumberingSeriesNotFound::class);
        $this->manage->revise($this->company, $this->invoices($globex)->getId(), new NumberingChanges('X-{SEQ}', 1, 'never'), null);
    }

    private function invoices(Company $company): NumberingSeries
    {
        return array_find($this->manage->list($company), static fn (NumberingSeries $s): bool => 'invoice' === $s->getDocumentType()) ?? self::fail('no invoice series');
    }
}

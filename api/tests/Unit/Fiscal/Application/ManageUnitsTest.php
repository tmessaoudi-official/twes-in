<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Application;

use App\Fiscal\Application\Unit\ManageUnits;
use App\Fiscal\Application\Unit\UnitChanges;
use App\Fiscal\Application\Unit\UnitCodeTaken;
use App\Fiscal\Application\Unit\UnitDraft;
use App\Fiscal\Application\Unit\UnitNotFound;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryUnits;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ManageUnitsTest extends TestCase
{
    private InMemoryUnits $units;
    private InMemoryAuditTrail $audit;
    private ManageUnits $manage;
    private Company $company;

    protected function setUp(): void
    {
        $this->units = new InMemoryUnits();
        $transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($transactions);
        $this->manage = new ManageUnits($this->units, $this->audit, new MockClock('2026-09-13 10:00:00'), $transactions);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }

    public function testACompanyAddsAUnitAndTheAdditionIsAudited(): void
    {
        $unit = $this->manage->create($this->company, new UnitDraft('TNE', 'Tonne', 3, 90), null);

        self::assertSame([$unit], $this->manage->list($this->company));
        self::assertSame('unit.created', $this->audit->entries[0]->action);
    }

    public function testTwoUnitsOfOneCompanyCannotShareACode(): void
    {
        $this->manage->create($this->company, new UnitDraft('TNE', 'Tonne', 3, 90), null);

        $this->expectException(UnitCodeTaken::class);
        $this->manage->create($this->company, new UnitDraft('TNE', 'Tonne métrique', 3, 90), null);
    }

    public function testARevisionIsAuditedOnlyWhenItChangesSomething(): void
    {
        $unit = $this->manage->create($this->company, new UnitDraft('TNE', 'Tonne', 3, 90), null);

        $this->manage->revise($this->company, $unit->getId(), new UnitChanges('Tonne', 3, true, 90), null);
        $this->manage->revise($this->company, $unit->getId(), new UnitChanges('Tonne', 3, false, 90), null);

        self::assertCount(2, $this->audit->entries);
        self::assertSame('unit.revised', $this->audit->entries[1]->action);
        self::assertFalse($unit->isActive());
    }

    public function testAnotherCompanysUnitIsNotFound(): void
    {
        $theirs = $this->manage->create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), new UnitDraft('TNE', 'Tonne', 3, 90), null);

        $this->expectException(UnitNotFound::class);
        $this->manage->revise($this->company, $theirs->getId(), new UnitChanges('Tonne', 3, true, 90), null);
    }
}

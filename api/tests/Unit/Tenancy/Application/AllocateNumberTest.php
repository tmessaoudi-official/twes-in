<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Tenancy\Application\Numbering\AllocateNumber;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\NumberingSeries;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AllocateNumberTest extends TestCase
{
    private InMemoryNumberingSeries $series;
    private FakeTransactions $transactions;
    private AllocateNumber $allocate;
    private Company $company;
    private Establishment $head;

    protected function setUp(): void
    {
        // Half past eleven on New Year's Eve in UTC is already half past midnight in Tunis.
        $clock = new MockClock('2026-12-31 23:30:00', 'UTC');
        $establishments = new InMemoryEstablishments();
        $this->series = new InMemoryNumberingSeries();
        $this->transactions = new FakeTransactions();
        new ProvisionCompany(ShippedFiscalPresets::presets(), new InMemoryTaxComponents(), new InMemoryUnits(), $establishments, $this->series, ShippedFiscalPresets::scales(), $clock)
            ->handle($this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $this->head = $establishments->ofCompany($this->company->getId())[0];
        $this->allocate = new AllocateNumber($this->series, $this->transactions, $clock);
    }

    public function testANumberIsTakenOnTheCompanysOwnDayInsideTheTransaction(): void
    {
        $allocated = $this->transactions->run(fn () => $this->allocate->allocate($this->company, $this->head, 'delivery_note'));

        self::assertSame('BL-2027-00001', $allocated->number);
        self::assertSame('2027-01-01', $allocated->issueDate->format('Y-m-d'));
        self::assertSame(2, $this->deliveryNotes()->getNextNumber());
        self::assertTrue($this->deliveryNotes()->isNumbered());
        self::assertSame(1, $this->transactions->committed);
    }

    public function testNoNumberIsTakenOutsideATransaction(): void
    {
        try {
            $this->allocate->allocate($this->company, $this->head, 'delivery_note');
            self::fail('A number was taken with no transaction to store its document in.');
        } catch (\LogicException) {
        }

        self::assertSame(1, $this->deliveryNotes()->getNextNumber());
        self::assertFalse($this->deliveryNotes()->isNumbered());
    }

    public function testADocumentTypeTheEstablishmentDoesNotNumberHasNoNumber(): void
    {
        $this->expectException(NoNumberingSeries::class);

        $this->transactions->run(fn () => $this->allocate->allocate($this->company, $this->head, 'quote'));
    }

    private function deliveryNotes(): NumberingSeries
    {
        return $this->series->lockedDefaultFor($this->head->getId(), 'delivery_note') ?? self::fail('no delivery note series');
    }
}

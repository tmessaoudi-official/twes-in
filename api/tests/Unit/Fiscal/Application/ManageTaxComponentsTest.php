<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Application;

use App\Fiscal\Application\TaxComponent\ManageTaxComponents;
use App\Fiscal\Application\TaxComponent\TaxComponentChanges;
use App\Fiscal\Application\TaxComponent\TaxComponentCodeTaken;
use App\Fiscal\Application\TaxComponent\TaxComponentDraft;
use App\Fiscal\Application\TaxComponent\TaxComponentNotFound;
use App\Fiscal\Domain\InvalidFiscalValue;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ManageTaxComponentsTest extends TestCase
{
    private InMemoryTaxComponents $components;
    private InMemoryAuditTrail $audit;
    private ManageTaxComponents $manage;
    private Company $company;

    protected function setUp(): void
    {
        $this->components = new InMemoryTaxComponents();
        $this->audit = new InMemoryAuditTrail();
        $this->manage = new ManageTaxComponents($this->components, ShippedFiscalPresets::scales(), $this->audit, new MockClock('2026-09-13 10:00:00'));
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }

    public function testACompanyAddsATaxAndTheAdditionIsAudited(): void
    {
        $actor = Uuid::v7();

        $component = $this->manage->create($this->company, self::draft('TVA12'), $actor);

        self::assertSame([$component], $this->manage->list($this->company));
        self::assertCount(1, $this->audit->entries);
        self::assertSame('tax_component.created', $this->audit->entries[0]->action);
        self::assertSame($actor, $this->audit->entries[0]->actorUserId);
        self::assertTrue($this->company->getId()->equals($this->audit->entries[0]->companyId));
        self::assertSame('12.000', $this->audit->entries[0]->changes['rate']);
    }

    public function testTwoTaxesOfOneCompanyCannotShareACode(): void
    {
        $this->manage->create($this->company, self::draft('TVA12'), null);

        $this->expectException(TaxComponentCodeTaken::class);
        $this->manage->create($this->company, self::draft('TVA12'), null);
    }

    public function testAnotherCompanyMayUseTheSameCode(): void
    {
        $this->manage->create($this->company, self::draft('TVA12'), null);

        $this->manage->create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), self::draft('TVA12'), null);

        self::assertCount(2, $this->components->components);
    }

    public function testAnInventedFamilyIsRefused(): void
    {
        $this->expectException(InvalidFiscalValue::class);
        $this->manage->create($this->company, new TaxComponentDraft('X1', 'X', 'luxury', '5', null, null, false, false, null, 0), null);
    }

    public function testTheCurrencyDecidesHowFineAnAmountMayBe(): void
    {
        $euro = new Company('Dupont', 'FR', 'EUR', 'fr', 'Europe/Paris');

        $this->expectException(InvalidFiscalValue::class);
        $this->manage->create($euro, new TaxComponentDraft('STAMP', 'Stamp', 'stamp', null, '1.005', null, false, false, null, 0), null);
    }

    public function testARevisionIsAuditedOnlyWhenItChangesSomething(): void
    {
        $component = $this->manage->create($this->company, self::draft('TVA12'), null);

        $this->manage->revise($this->company, $component->getId(), self::changes('12'), null);
        self::assertCount(1, $this->audit->entries);

        $this->manage->revise($this->company, $component->getId(), self::changes('12.5'), null);
        self::assertCount(2, $this->audit->entries);
        self::assertSame('tax_component.revised', $this->audit->entries[1]->action);
        self::assertSame('12.500', $component->getRate());
    }

    public function testAnotherCompanysTaxIsNotFound(): void
    {
        $theirs = $this->manage->create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), self::draft('TVA12'), null);

        $this->expectException(TaxComponentNotFound::class);
        $this->manage->revise($this->company, $theirs->getId(), self::changes('5'), null);
    }

    private static function draft(string $code): TaxComponentDraft
    {
        return new TaxComponentDraft($code, 'TVA 12 %', 'vat', '12', null, null, false, false, null, 10);
    }

    private static function changes(string $rate): TaxComponentChanges
    {
        return new TaxComponentChanges('TVA 12 %', $rate, null, null, false, false, true, null, 10);
    }
}

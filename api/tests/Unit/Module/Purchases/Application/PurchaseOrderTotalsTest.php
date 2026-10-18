<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Purchases\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Model\Unit;
use App\Module\Purchases\Application\PurchaseOrderTotals;
use App\Module\Purchases\Domain\Model\PurchaseOrder;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderHeader;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderLineDetails;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** What an order to a vendor comes to: the screen and the printed order read the same figures. */
final class PurchaseOrderTotalsTest extends TestCase
{
    private Company $company;
    private InMemoryUnits $units;
    private InMemoryEstablishments $establishments;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-10-18 09:00:00');
        $this->now = $clock->now();
        $this->units = new InMemoryUnits();
        $this->establishments = new InMemoryEstablishments();
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        new ProvisionCompany(ShippedFiscalPresets::presets(), new InMemoryTaxComponents(), $this->units, $this->establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $clock)->handle($this->company);
    }

    public function testEachLineComesToItsQuantityTimesItsPriceRoundedAtTheCurrencyScaleAndTheOrderToTheirSum(): void
    {
        $totals = PurchaseOrderTotals::of($this->order([['500', '0.2500'], ['3', '0.3333']]), 3);

        self::assertSame(['125.000', '1.000'], $totals->nets);
        self::assertSame('126.000', $totals->total);
        self::assertSame(['125.00', '1.00'], PurchaseOrderTotals::of($this->order([['500', '0.2500'], ['3', '0.3333']]), 2)->nets);
    }

    public function testALineWithoutAPriceComesToNothingAndLeavesTheOrderWithoutATotal(): void
    {
        $totals = PurchaseOrderTotals::of($this->order([['500', '0.2500'], ['2', null]]), 3);

        self::assertSame(['125.000', null], $totals->nets);
        self::assertNull($totals->total);
        self::assertNull(PurchaseOrderTotals::of($this->order([]), 3)->total, 'an order of no line comes to nothing yet');
    }

    /** @param list<array{string, string|null}> $lines quantity and price */
    private function order(array $lines): PurchaseOrder
    {
        $piece = $this->units->ofCodeInCompany('C62', $this->company->getId());
        self::assertInstanceOf(Unit::class, $piece);

        return PurchaseOrder::create($this->company, $this->establishments->ofCompany($this->company->getId())[0], Uuid::v7(), 'Quincaillerie du Sud', new PurchaseOrderHeader(), array_map(
            static fn (array $line): PurchaseOrderLineDetails => new PurchaseOrderLineDetails(Uuid::v7(), 'VIS-6', 'Vis 6 mm', $line[0], $piece, $line[1]),
            $lines,
        ), $this->now);
    }
}

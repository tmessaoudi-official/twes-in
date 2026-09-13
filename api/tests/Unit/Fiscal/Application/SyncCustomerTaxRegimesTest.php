<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Application;

use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxFamily;
use App\Tests\Support\InMemoryCustomerTaxRegimes;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SyncCustomerTaxRegimesTest extends TestCase
{
    private InMemoryCustomerTaxRegimes $regimes;
    private SyncCustomerTaxRegimes $sync;

    protected function setUp(): void
    {
        $this->regimes = new InMemoryCustomerTaxRegimes();
        $this->sync = new SyncCustomerTaxRegimes(ShippedFiscalPresets::presets(), $this->regimes, new MockClock('2026-09-13 10:00:00'));
    }

    public function testEveryPresetsRegimesAreWritten(): void
    {
        self::assertSame(['customer tax regimes of FR', 'customer tax regimes of TN'], $this->sync->handle());

        self::assertSame(['standard', 'exempt', 'suspended', 'export'], array_map(static fn (CustomerTaxRegime $r) => $r->getCode(), $this->regimes->ofPreset('TN')));
        self::assertSame(['standard', 'exempt', 'intra_eu', 'export'], array_map(static fn (CustomerTaxRegime $r) => $r->getCode(), $this->regimes->ofPreset('FR')));
        $export = $this->regimes->ofPresetAndCode('TN', 'export');
        self::assertNotNull($export);
        self::assertSame([TaxFamily::Vat], $export->getExcludedFamilies());
        self::assertSame('fiscal.mention.tn.export', $export->getMentionKey());
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->sync->handle();

        self::assertSame([], $this->sync->handle());
        self::assertCount(8, $this->regimes->regimes);
    }

    public function testARegimeAnEarlierReleaseDefinedDifferentlyIsBroughtUpToDate(): void
    {
        $this->regimes->save(new CustomerTaxRegime('TN', 'export', 'fiscal.regime.export', [], null, 99, new \DateTimeImmutable('2026-01-01')));

        self::assertContains('customer tax regimes of TN', $this->sync->handle());
        self::assertSame([TaxFamily::Vat], $this->regimes->ofPresetAndCode('TN', 'export')?->getExcludedFamilies());
        self::assertCount(8, $this->regimes->regimes);
    }

    public function testARegimeThePresetNoLongerListsIsKept(): void
    {
        $this->regimes->save(new CustomerTaxRegime('TN', 'retired', 'fiscal.regime.standard', [], null, 9, new \DateTimeImmutable('2026-01-01')));

        $this->sync->handle();

        self::assertNotNull($this->regimes->ofPresetAndCode('TN', 'retired'));
    }
}

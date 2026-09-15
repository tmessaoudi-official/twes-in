<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Domain;

use App\Module\Inventory\Domain\InvalidStockLocation;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StockLocationTest extends TestCase
{
    private Company $company;
    private Establishment $main;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-15 09:00:00');
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->main = Establishment::create($this->company, '000', 'Siège de Tunis', true, $this->now);
    }

    public function testAnEstablishmentsDefaultLocationIsTheTopOfItsTreeCodedAndNamedAfterIt(): void
    {
        $site = StockLocation::defaultOf($this->main, $this->now);

        self::assertSame([StockLocationKind::Site, '000', 'Siège de Tunis', true, null], [$site->getKind(), $site->getCode(), $site->getName(), $site->isDefault(), $site->getParent()]);
        self::assertSame($this->main, $site->getEstablishment());
        self::assertTrue($this->company->getId()->equals($site->getCompany()->getId()));
    }

    public function testALocationSitsUnderAnotherOfItsEstablishment(): void
    {
        $site = StockLocation::defaultOf($this->main, $this->now);

        $rack = StockLocation::create($this->main, $site, StockLocationKind::Rack, ' R1 ', 'Rayonnage 1', $this->now);

        self::assertSame([$site, StockLocationKind::Rack, 'R1', 'Rayonnage 1', false], [$rack->getParent(), $rack->getKind(), $rack->getCode(), $rack->getName(), $rack->isDefault()]);
    }

    public function testALocationWithoutAParentOrUnderAnotherEstablishmentsLocationIsRefused(): void
    {
        $depot = StockLocation::defaultOf(Establishment::create($this->company, '001', 'Dépôt', false, $this->now), $this->now);

        foreach ([null, $depot] as $parent) {
            try {
                StockLocation::create($this->main, $parent, StockLocationKind::Zone, 'Z1', 'Zone 1', $this->now);
                self::fail('A location sat outside its establishment\'s tree.');
            } catch (InvalidStockLocation $refused) {
                self::assertSame('parentId', $refused->field);
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function refusedCodesAndNames(): iterable
    {
        yield 'an empty code' => ['', 'Zone 1', 'code'];
        yield 'a code with a space' => ['Z 1', 'Zone 1', 'code'];
        yield 'a code of 33 characters' => [str_repeat('Z', 33), 'Zone 1', 'code'];
        yield 'a blank name' => ['Z1', '   ', 'name'];
        yield 'a name of 121 characters' => ['Z1', str_repeat('z', 121), 'name'];
    }

    #[DataProvider('refusedCodesAndNames')]
    public function testACodeAndANameFollowTheirRules(string $code, string $name, string $field): void
    {
        $site = StockLocation::defaultOf($this->main, $this->now);

        try {
            StockLocation::create($this->main, $site, StockLocationKind::Zone, $code, $name, $this->now);
            self::fail('The location was accepted.');
        } catch (InvalidStockLocation $refused) {
            self::assertSame($field, $refused->field);
        }
    }

    public function testAcceptedCodesUseLettersDigitsDotsDashesAndUnderscores(): void
    {
        $site = StockLocation::defaultOf($this->main, $this->now);

        self::assertSame('A-1_b.2', StockLocation::create($this->main, $site, StockLocationKind::Bin, 'A-1_b.2', 'Casier', $this->now)->getCode());
        self::assertSame(32, \strlen(StockLocation::create($this->main, $site, StockLocationKind::Bin, str_repeat('B', 32), 'Casier', $this->now)->getCode()));
    }

    public function testARevisionSaysWhatChangedAndNeverMakesACycle(): void
    {
        $site = StockLocation::defaultOf($this->main, $this->now);
        $zone = StockLocation::create($this->main, $site, StockLocationKind::Zone, 'Z1', 'Zone 1', $this->now);
        $rack = StockLocation::create($this->main, $zone, StockLocationKind::Rack, 'R1', 'Rayonnage 1', $this->now);

        self::assertSame([], $zone->revise($site, StockLocationKind::Zone, 'Z1', 'Zone 1', $this->now));
        self::assertSame(['name'], $zone->revise($site, StockLocationKind::Zone, 'Z1', 'Zone A', $this->now));
        self::assertSame(['kind', 'code'], $zone->revise($site, StockLocationKind::Building, 'B1', 'Zone A', $this->now));
        self::assertSame(['parentId'], $rack->revise($site, StockLocationKind::Rack, 'R1', 'Rayonnage 1', $this->now));

        $rack->revise($zone, StockLocationKind::Rack, 'R1', 'Rayonnage 1', $this->now);
        foreach ([$zone, $rack, null] as $parent) {
            try {
                $zone->revise($parent, StockLocationKind::Building, 'B1', 'Zone A', $this->now);
                self::fail('The zone was placed under itself, under its own rack, or outside the tree.');
            } catch (InvalidStockLocation $refused) {
                self::assertSame('parentId', $refused->field);
            }
        }
        self::assertSame($site, $zone->getParent());
    }

    public function testTheDefaultLocationStaysAtTheTopOfItsTree(): void
    {
        $site = StockLocation::defaultOf($this->main, $this->now);
        $zone = StockLocation::create($this->main, $site, StockLocationKind::Zone, 'Z1', 'Zone 1', $this->now);

        self::assertSame(['name'], $site->revise(null, StockLocationKind::Site, '000', 'Magasin de Tunis', $this->now));
        try {
            $site->revise($zone, StockLocationKind::Site, '000', 'Magasin de Tunis', $this->now);
            self::fail('The default location was placed under another.');
        } catch (InvalidStockLocation $refused) {
            self::assertSame('parentId', $refused->field);
        }
        self::assertNull($site->getParent());
    }
}

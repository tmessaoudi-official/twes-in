<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Domain;

use App\Fiscal\Domain\Calculation\QuantityTotal;
use App\Fiscal\Domain\Calculation\QuantityTotals;
use App\Fiscal\Domain\Model\Unit;
use App\Tenancy\Domain\Model\Company;
use PHPUnit\Framework\TestCase;

/** What a document's lines come to in each unit: the « Quantités » line under its lines. */
final class QuantityTotalsTest extends TestCase
{
    public function testEachUnitAddsItsLinesExactlyInTheOrderTheUnitsFirstAppear(): void
    {
        $totals = QuantityTotals::of([
            ['unit' => 'u-kg', 'name' => 'kg', 'decimals' => 3, 'quantity' => '1.100'],
            ['unit' => 'u-pc', 'name' => 'pièce', 'decimals' => 0, 'quantity' => '4'],
            ['unit' => 'u-kg', 'name' => 'kg', 'decimals' => 3, 'quantity' => '2.200'],
            ['unit' => 'u-pc', 'name' => 'pièce', 'decimals' => 0, 'quantity' => '8'],
        ]);

        self::assertEquals([new QuantityTotal('kg', 3, '3.300'), new QuantityTotal('pièce', 0, '12')], $totals);
    }

    public function testEachUnitIsNamedInTheDocumentsLanguage(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $now = new \DateTimeImmutable('2026-10-19 10:00:00');
        $piece = Unit::create($company, 'C62', 'Unité', 0, 10, $now, ['ar' => 'وحدة']);
        $kilo = Unit::create($company, 'KGM', 'Kilogramme', 3, 20, $now);

        $totals = QuantityTotals::ofUnits([[$piece, '2'], [$kilo, '1.500'], [$piece, '3']], 'ar');

        self::assertEquals([new QuantityTotal('وحدة', 0, '5'), new QuantityTotal('Kilogramme', 3, '1.500')], $totals);
    }

    public function testOneLineHasNothingToAddUp(): void
    {
        self::assertSame([], QuantityTotals::of([['unit' => 'u-pc', 'name' => 'pièce', 'decimals' => 0, 'quantity' => '4']]));
    }
}

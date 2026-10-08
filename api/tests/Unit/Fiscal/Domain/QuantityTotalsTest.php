<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Domain;

use App\Fiscal\Domain\Calculation\QuantityTotal;
use App\Fiscal\Domain\Calculation\QuantityTotals;
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

    public function testOneLineHasNothingToAddUp(): void
    {
        self::assertSame([], QuantityTotals::of([['unit' => 'u-pc', 'name' => 'pièce', 'decimals' => 0, 'quantity' => '4']]));
    }
}

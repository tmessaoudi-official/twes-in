<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Domain;

use App\Module\Inventory\Domain\WeightedAverageCost;
use PHPUnit\Framework\TestCase;

final class WeightedAverageCostTest extends TestCase
{
    public function testItIsTheValueOfTheValuedStockOverItsQuantity(): void
    {
        self::assertSame('600.0000', WeightedAverageCost::of('20.000', '12000.0000000', '1'));
        self::assertSame('3.3333', WeightedAverageCost::of('3.000', '10.0000000', null));
    }

    public function testWithNothingValuedItIsTheProductsCostPriceOrUnknown(): void
    {
        self::assertSame('8.0000', WeightedAverageCost::of('0.000', '0.0000000', '8'));
        self::assertSame('8.0000', WeightedAverageCost::of('-2.000', '-20.0000000', '8'), 'a stock of less than nothing has no average to keep');
        self::assertNull(WeightedAverageCost::of('0.000', '0.0000000', null));
    }
}

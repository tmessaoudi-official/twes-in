<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Domain;

use App\Module\Inventory\Domain\LateCost;
use PHPUnit\Framework\TestCase;

/** A late receipt cost split between the stock left and the sales since (docs/SPEC.md § 7, the B-F4 ruling). */
final class LateCostTest extends TestCase
{
    public function testHalfTheStockGoneTakesHalfTheDifference(): void
    {
        self::assertSame('15.0000000', LateCost::soldShare('30', '10.000', ['-5.000']));
    }

    public function testALaterArrivalTakesNothingBackAndASaleAfterItTakesItsShareOfTheWholeStock(): void
    {
        // 10 after the receipt; 5 sold (half kept); 10 more arrive (15 on hand); 3 sold, a fifth of 15: 0.5 x 0.8 = 0.4 kept.
        self::assertSame('18.0000000', LateCost::soldShare('30', '10.000', ['-5.000', '10.000', '-3.000']));
    }

    public function testACostEnteredBelowTheProvisionalOneGivesBackWhatTheSalesOverstated(): void
    {
        self::assertSame('-15.0000000', LateCost::soldShare('-30', '10.000', ['-5.000']));
    }

    public function testNothingGoneIsNothingToBookAndEverythingGoneIsTheWholeDifference(): void
    {
        self::assertNull(LateCost::soldShare('30', '10.000', []));
        self::assertNull(LateCost::soldShare('30', '10.000', ['4.000']));
        self::assertSame('30.0000000', LateCost::soldShare('30', '10.000', ['-10.000', '6.000']));
        self::assertSame('30.0000000', LateCost::soldShare('30', '10.000', ['-12.000']), 'more out than was there: all of it went');
        self::assertSame('30.0000000', LateCost::soldShare('30', '0.000', []), 'a receipt that only filled a shortfall was sold before it came');
    }
}

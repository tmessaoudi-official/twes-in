<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Domain;

use App\Module\Inventory\Domain\RunningValue;
use PHPUnit\Framework\TestCase;

final class RunningValueTest extends TestCase
{
    public function testGoodsLiftingAStockBelowNothingSetItsWorthAtTheirOwnCost(): void
    {
        $sold = new RunningValue('-2.000', '-20.0000000');

        self::assertSame('18.0000000', $sold->revaluationFor('3.000', '1') ?? '', 'the two missing units are worth 2 at that cost, not 20: -20 + 3 + 18 = 1');
        self::assertSame(['1.000', '1.0000000', '1.0000'], self::read($sold->after('3.000', '1')));
        self::assertSame(['0.000', '0.0000000', '7.0000'], self::read($sold->after('2.000', '1')), 'filled exactly, the stock is worth nothing; the cost price stands in');
    }

    public function testAStockThatStaysBelowNothingOrWasAboveItIsSummedAsBefore(): void
    {
        $sold = new RunningValue('-5.000', '-500.0000000');
        self::assertNull($sold->revaluationFor('3.000', '60'), 'still two missing: nothing on the shelf to value yet');
        self::assertSame(['-2.000', '-320.0000000'], \array_slice(self::read($sold->after('3.000', '60')), 0, 2));

        $held = new RunningValue('2.000', '20.0000000');
        self::assertNull($held->revaluationFor('3.000', '4'));
        self::assertSame(['5.000', '32.0000000', '6.4000'], self::read($held->after('3.000', '4')));
        self::assertNull($held->revaluationFor('-5.000', '10'), 'goods going out never revalue');
    }

    public function testAStockEmptiedAtItsAverageIsNotRevaluedWhenItFillsAgain(): void
    {
        $emptied = new RunningValue('2.000', '20.0000000')->after('-3.000', '10');

        self::assertNull($emptied->revaluationFor('3.000', '10'));
        self::assertNull(new RunningValue('0.000', '0.0000000')->revaluationFor('4.000', '9'));
    }

    /** @return list<string|null> */
    private static function read(RunningValue $value): array
    {
        return [$value->quantity, $value->amount, $value->average('7')];
    }
}

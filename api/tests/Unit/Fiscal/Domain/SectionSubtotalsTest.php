<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Domain;

use App\Fiscal\Domain\Calculation\SectionSubtotal;
use App\Fiscal\Domain\Calculation\SectionSubtotals;
use PHPUnit\Framework\TestCase;

/**
 * A document's sections: a line carrying a title opens one, which runs to the next titled line; what comes before the
 * first title is in none. A section's subtotal adds its lines' nets exactly, at the currency's scale.
 */
final class SectionSubtotalsTest extends TestCase
{
    public function testEachTitledLineOpensASectionThatRunsToTheNextOne(): void
    {
        $sections = SectionSubtotals::of([
            [null, '10.000'],
            ['Démontage', '0.100'],
            [null, '0.200'],
            ['Pièces', '1250.000'],
            [null, '0.001'],
            [null, '99999999999.999'],
        ], 3);

        self::assertEquals([
            new SectionSubtotal('Démontage', 1, 2, '0.300'),
            new SectionSubtotal('Pièces', 3, 3, '100000001250.000'),
        ], $sections);
    }

    public function testADocumentWithoutTitlesHasNoSections(): void
    {
        self::assertSame([], SectionSubtotals::of([[null, '1.00'], [null, '2.00']], 2));
        self::assertSame([], SectionSubtotals::of([], 2));
    }

    public function testASubtotalIsWrittenAtTheCurrencysScaleAndOneLineMakesASection(): void
    {
        self::assertEquals([new SectionSubtotal('Seul', 0, 1, '3.50')], SectionSubtotals::of([['Seul', '3.5']], 2));
    }

    public function testNetsAlreadyAtTheCurrencysScaleAreAddedAsWritten(): void
    {
        self::assertEquals([new SectionSubtotal('Pose', 0, 2, '25.50')], SectionSubtotals::of([['Pose', '10.50'], [null, '15.00']]));
        self::assertEquals([new SectionSubtotal('Pose', 0, 1, '0.000')], SectionSubtotals::of([['Pose', '0.000']]));
    }
}

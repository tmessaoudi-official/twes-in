<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Domain;

use App\Module\Invoices\Domain\InvoiceFigures;
use PHPUnit\Framework\TestCase;

/**
 * « Net à payer » is the total less what is withheld at source, whatever has been paid or credited since: the figure
 * the paid, credited and left amounts add up to. The screen shows it as the API counts it, never redoing the sum.
 */
final class InvoiceFiguresTest extends TestCase
{
    public function testWhatIsToPayIsTheTotalLessTheWithholdingAndNotWhatIsStillDue(): void
    {
        $figures = self::figures(total: '14917.650', withheld: '149.167', due: '4768.483', paid: '10000.000')->atScale(3);

        self::assertSame('14768.483', $figures->netToPay());
    }

    public function testWithoutAWithholdingItIsTheTotal(): void
    {
        self::assertSame('2143.00', self::figures(total: '2143.000', withheld: '0.000', due: '2143.000', paid: '0.000')->atScale(2)->netToPay());
    }

    private static function figures(string $total, string $withheld, string $due, string $paid): InvoiceFigures
    {
        return new InvoiceFigures($total, '0.000', $total, [], '0.000', [], $total, [], $withheld, $due, [], $paid);
    }
}

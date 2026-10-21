<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Application\Cost;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Products\Application\Cost\PurchaseCost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A purchase's cost from what the vendor invoiced taxes included: the VAT the company gets back is left out, a levy
 * that is no VAT stays in, and a company that gets none back keeps the figure.
 */
final class PurchaseCostTest extends TestCase
{
    /** @return iterable<string, array{string, bool, string, string}> */
    public static function vectors(): iterable
    {
        yield 'VAT got back is left out' => ['119', true, '19', '100.0000'];
        yield 'at another rate' => ['107', true, '7', '100.0000'];
        // 100 net, FODEC 1 % on it, VAT 19 % on both: 100 × 1.01 × 1.19 = 120.19, and the cost keeps the 1.
        yield 'a levy entering the VAT base stays in' => ['120.19', true, '19', '101.0000'];
        yield 'to four decimals, half up' => ['10', true, '19', '8.4034'];
        yield 'a regime getting no VAT back keeps the figure' => ['119', false, '19', '119.0000'];
        yield 'nothing to get back at no rate' => ['12.5', true, '0', '12.5000'];
    }

    #[DataProvider('vectors')]
    public function testACostTaxesIncludedLeavesOutOnlyTheVatGotBack(string $gross, bool $vatRecovered, string $vatRate, string $cost): void
    {
        self::assertSame($cost, PurchaseCost::fromGross(Decimal::of($gross), $vatRecovered, Decimal::of($vatRate)));
    }
}

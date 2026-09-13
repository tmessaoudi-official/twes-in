<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Infrastructure;

use App\Fiscal\Infrastructure\Currency\IntlCurrencyScales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntlCurrencyScalesTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function currencies(): iterable
    {
        yield 'the dinar has millimes' => ['TND', 3];
        yield 'the euro has cents' => ['EUR', 2];
        yield 'the yen has none' => ['JPY', 0];
        yield 'lower case is the same currency' => ['tnd', 3];
    }

    #[DataProvider('currencies')]
    public function testACurrencyHasItsIsoDecimals(string $currency, int $decimals): void
    {
        self::assertSame($decimals, (new IntlCurrencyScales())->of($currency));
    }

    public function testAnInventedCurrencyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new IntlCurrencyScales())->of('ZZZ');
    }
}

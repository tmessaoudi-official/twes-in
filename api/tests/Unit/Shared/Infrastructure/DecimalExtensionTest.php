<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\Twig\DecimalExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFilter;

final class DecimalExtensionTest extends TestCase
{
    public function testADecimalIsWrittenTheWayItsLanguageWritesNumbersWithoutLosingADigit(): void
    {
        $decimal = new DecimalExtension();
        $space = "\u{a0}";

        foreach ([
            ['1250.0000', 3, 'fr', "1{$space}250,000"],
            ['1250.0000', 3, 'en', '1,250.000'],
            ['12.3456', 3, 'en', '12.3456'],
            ['990.5000', 3, 'fr', '990,500'],
            ['2.000', 0, 'fr', '2'],
            ['0.125', 3, 'fr', '0,125'],
            ['-1234567.5', 2, 'en', '-1,234,567.50'],
            ['9999999999999.9999', 2, 'en', '9,999,999,999,999.9999'],
            ['311.780', 3, 'en', '311.780'],
        ] as [$value, $minimum, $language, $written]) {
            self::assertSame($written, $decimal->format($value, $minimum, $language), "$value in $language");
        }
    }

    public function testWhatIsNotADecimalIsRefusedAndTheFilterIsNamedDecimal(): void
    {
        self::assertSame(['decimal'], array_map(static fn (TwigFilter $filter): string => $filter->getName(), new DecimalExtension()->getFilters()));

        $this->expectException(\InvalidArgumentException::class);
        new DecimalExtension()->format('1e3', 2, 'fr');
    }
}

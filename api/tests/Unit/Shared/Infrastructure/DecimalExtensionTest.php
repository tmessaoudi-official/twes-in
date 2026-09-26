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
use Twig\TwigFunction;

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

    // docs/SPEC.md § 7, 2026-09-25 12:45, row 130: the company's number format wins over the language; `auto` is the language's.
    public function testAChosenNumberFormatWinsOverTheLanguage(): void
    {
        $decimal = new DecimalExtension();
        $space = "\u{a0}";
        foreach ([
            ['1250.0000', 3, 'fr', 'comma-dot', '1,250.000'],
            ['1250.0000', 3, 'en', 'space-comma', "1{$space}250,000"],
            ['-1234567.5', 2, 'fr', 'dot-comma', '-1.234.567,50'],
            ['1250.0000', 3, 'en', 'auto', '1,250.000'],
            ['1250.0000', 3, 'fr', 'auto', "1{$space}250,000"],
        ] as [$value, $minimum, $language, $style, $written]) {
            self::assertSame($written, $decimal->format($value, $minimum, $language, $style), "$value in $language, $style");
        }
        self::assertSame('−1.250,50', $decimal->deduction('1250.5', 2, 'en', 'dot-comma'));
    }

    public function testADayIsWrittenInTheChosenOrderOrTheLanguagesPattern(): void
    {
        $decimal = new DecimalExtension();
        foreach (['dmy' => 'd/m/Y', 'mdy' => 'm/d/Y', 'ymd' => 'Y-m-d', 'dmy-dots' => 'd.m.Y', 'auto' => 'the language\'s'] as $style => $pattern) {
            self::assertSame($pattern, $decimal->datePattern($style, 'the language\'s'), $style);
        }
    }

    public function testADeductionIsWrittenWithOneSignWhateverTheSignOfTheAmount(): void
    {
        $decimal = new DecimalExtension();
        $space = "\u{a0}";

        // An invoice's withholding (15.126) prints as a deduction; a credit note's (-15.126) gives back what it took,
        // so it prints without the sign rather than as "−-15,126" (row 29).
        foreach ([
            ['15.126', 3, 'fr', '−15,126'],
            ['-15.126', 3, 'fr', '15,126'],
            ['1250.5', 3, 'fr', "−1{$space}250,500"],
            ['-1250.5', 2, 'en', '1,250.50'],
            ['0.000', 3, 'fr', '0,000'],
            ['-0.000', 3, 'fr', '0,000'],
        ] as [$value, $minimum, $language, $written]) {
            self::assertSame($written, $decimal->deduction($value, $minimum, $language), "$value in $language");
        }
    }

    public function testWhatIsNotADecimalIsRefusedAndTheFiltersAreNamed(): void
    {
        self::assertSame(['date_pattern'], array_map(static fn (TwigFunction $function): string => $function->getName(), new DecimalExtension()->getFunctions()));
        self::assertSame(['decimal', 'deduction'], array_map(static fn (TwigFilter $filter): string => $filter->getName(), new DecimalExtension()->getFilters()));

        $this->expectException(\InvalidArgumentException::class);
        new DecimalExtension()->format('1e3', 2, 'fr');
    }
}

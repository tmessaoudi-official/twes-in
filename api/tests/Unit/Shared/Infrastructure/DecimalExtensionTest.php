<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\Twig\DecimalExtension;
use App\Tests\Support\ShippedLanguages;
use App\Tests\Support\ShippedPdfTexts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class DecimalExtensionTest extends TestCase
{
    public function testADecimalIsWrittenTheWayItsLanguageWritesNumbersWithoutLosingADigit(): void
    {
        $decimal = new DecimalExtension(ShippedPdfTexts::translator(), ShippedLanguages::catalogue());
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
    /** A language's separators are its texts, so a new language brings its own and a language without them reads the default's. */
    public function testTheSeparatorsAreTheLanguagesTexts(): void
    {
        $translator = ShippedPdfTexts::translator();
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['format.decimal_point' => '·', 'format.thousands_separator' => '\''], 'xx', 'pdf');
        $decimal = new DecimalExtension($translator, ShippedLanguages::catalogue());

        self::assertSame("1'250·5", $decimal->format('1250.5', 1, 'xx'));
        self::assertSame("1\u{a0}250,5", $decimal->format('1250.5', 1, 'yy'), 'the default language\'s');
    }

    public function testAChosenNumberFormatWinsOverTheLanguage(): void
    {
        $decimal = new DecimalExtension(ShippedPdfTexts::translator(), ShippedLanguages::catalogue());
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
        $decimal = new DecimalExtension(ShippedPdfTexts::translator(), ShippedLanguages::catalogue());
        foreach (['dmy' => 'd/m/Y', 'mdy' => 'm/d/Y', 'ymd' => 'Y-m-d', 'dmy-dots' => 'd.m.Y', 'auto' => 'the language\'s'] as $style => $pattern) {
            self::assertSame($pattern, $decimal->datePattern($style, 'the language\'s'), $style);
        }
    }

    public function testADeductionIsWrittenWithOneSignWhateverTheSignOfTheAmount(): void
    {
        $decimal = new DecimalExtension(ShippedPdfTexts::translator(), ShippedLanguages::catalogue());
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

    /**
     * In a document read right to left, a sign before a figure is a neutral character and goes to the figure's right
     * (« 15,126− »): the left-to-right mark before it keeps it on the left, as Unicode's own Arabic formats write it.
     */
    public function testASignedFigureKeepsItsSignOnTheLeftInADocumentReadRightToLeft(): void
    {
        $decimal = new DecimalExtension(ShippedPdfTexts::translator(), ShippedLanguages::catalogue());
        $mark = "\u{200e}";
        $space = "\u{a0}";

        self::assertSame("{$mark}-1{$space}234,5", $decimal->format('-1234.5', 1, 'ar'));
        self::assertSame("{$mark}−15,126", $decimal->deduction('15.126', 3, 'ar'));
        self::assertSame("{$mark}-1,234.5", $decimal->format('-1234.5', 1, 'ar', 'comma-dot'), 'the mark is the language\'s, whatever separators were chosen');
        self::assertSame("1{$space}250,5", $decimal->format('1250.5', 1, 'ar'), 'a figure with no sign needs no mark');
        self::assertSame('15,126', $decimal->deduction('-15.126', 3, 'ar'), 'nor does a deduction written without one');
        self::assertSame('-1,5', $decimal->format('-1.5', 1, 'fr'), 'a language read left to right is left as it was');
        self::assertSame('−15,126', $decimal->deduction('15.126', 3, 'fr'));
    }

    public function testWhatIsNotADecimalIsRefusedAndTheFiltersAreNamed(): void
    {
        self::assertSame(['date_pattern'], array_map(static fn (TwigFunction $function): string => $function->getName(), new DecimalExtension(ShippedPdfTexts::translator(), ShippedLanguages::catalogue())->getFunctions()));
        self::assertSame(['decimal', 'deduction'], array_map(static fn (TwigFilter $filter): string => $filter->getName(), new DecimalExtension(ShippedPdfTexts::translator(), ShippedLanguages::catalogue())->getFilters()));

        $this->expectException(\InvalidArgumentException::class);
        new DecimalExtension(ShippedPdfTexts::translator(), ShippedLanguages::catalogue())->format('1e3', 2, 'fr');
    }
}

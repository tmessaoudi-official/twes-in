<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\Twig\DecimalExtension;
use App\Shared\Infrastructure\Twig\TaxLabelExtension;
use App\Tests\Support\ShippedLanguages;
use App\Tests\Support\ShippedPdfTexts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxLabelExtensionTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function labels(): iterable
    {
        $space = "\u{a0}";
        yield 'a name that says its rate is printed as it is' => ['TVA 19 %', '19.000', 'fr', 'auto', 'TVA 19 %'];
        yield 'an Arabic name that says its rate' => ['أ.ق.م 19 %', '19.000', 'ar', 'auto', 'أ.ق.م 19 %'];
        yield 'a name written without a space before the sign' => ['VAT 19%', '19', 'en', 'auto', 'VAT 19%'];
        yield 'a decimal rate said with a comma' => ['TVA 5,5 %', '5.500', 'fr', 'auto', 'TVA 5,5 %'];
        yield 'a decimal rate said with a point' => ['VAT 5.5%', '5.500', 'en', 'auto', 'VAT 5.5%'];
        yield 'a name writing the rate with zeros after it' => ['TVA 19,00 %', '19.000', 'fr', 'auto', 'TVA 19,00 %'];
        yield 'a decimal rate written with a zero after it' => ['TVA 5,50 %', '5.500', 'fr', 'auto', 'TVA 5,50 %'];
        yield 'nineteen is not nineteen and a half' => ['TVA 19,5 %', '19.000', 'fr', 'auto', "TVA 19,5 % (19{$space}%)"];
        yield 'a name without its rate gains the rate it froze' => ['TVA', '19.000', 'fr', 'auto', "TVA (19{$space}%)"];
        yield 'a code where no name is known' => ['TVA19', '19.000', 'fr', 'auto', "TVA19 (19{$space}%)"];
        yield 'a decimal rate in the language of the document' => ['TVA', '5.500', 'fr', 'auto', "TVA (5,5{$space}%)"];
        yield 'the company number format wins' => ['TVA', '5.500', 'fr', 'comma-dot', "TVA (5.5{$space}%)"];
        yield 'a name saying another rate still shows the rate the document froze' => ['TVA 13 %', '19.000', 'fr', 'auto', "TVA 13 % (19{$space}%)"];
        yield 'nineteen is not one' => ['FODEC 1 %', '19.000', 'fr', 'auto', "FODEC 1 % (19{$space}%)"];
        yield 'nineteen is not a hundred and nineteen' => ['TVA 119 %', '19.000', 'fr', 'auto', "TVA 119 % (19{$space}%)"];
        yield 'nineteen is not one and nine tenths' => ['TVA 1,19 %', '19.000', 'fr', 'auto', "TVA 1,19 % (19{$space}%)"];
    }

    #[DataProvider('labels')]
    public function testATaxIsNamedWithTheRateItFrozeUnlessItsNameSaysIt(string $name, string $rate, string $language, string $style, string $label): void
    {
        $extension = new TaxLabelExtension(new DecimalExtension(ShippedPdfTexts::translator(), ShippedLanguages::catalogue()));

        self::assertSame($label, $extension->label($name, $rate, $language, $style));
    }
}

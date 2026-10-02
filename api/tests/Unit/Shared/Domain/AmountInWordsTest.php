<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\AmountInWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountInWordsTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function amounts(): iterable
    {
        yield 'dinars and millimes' => ['1234.578', 'TND', 'fr', 'mille deux cent trente-quatre dinars et cinq cent soixante-dix-huit millimes'];
        yield 'whole dinars leave the millimes out' => ['21.000', 'TND', 'fr', 'vingt et un dinars'];
        yield 'a single dinar is singular' => ['1.001', 'TND', 'fr', 'un dinar et un millime'];
        yield 'under a dinar says zero' => ['0.500', 'TND', 'fr', 'zéro dinar et cinq cents millimes'];
        yield 'zero says zero' => ['0.000', 'TND', 'fr', 'zéro dinar'];
        yield 'quatre-vingts takes its s only at the end' => ['80.000', 'TND', 'fr', 'quatre-vingts dinars'];
        yield 'quatre-vingt followed by a number has none' => ['81.080', 'TND', 'fr', 'quatre-vingt-un dinars et quatre-vingts millimes'];
        yield 'soixante et onze' => ['71.000', 'TND', 'fr', 'soixante et onze dinars'];
        yield 'quatre-vingt-dix-sept' => ['97.000', 'TND', 'fr', 'quatre-vingt-dix-sept dinars'];
        yield 'cent alone and cents at the end' => ['100.000', 'TND', 'fr', 'cent dinars'];
        yield 'deux cents' => ['200.000', 'TND', 'fr', 'deux cents dinars'];
        yield 'deux cent trois has no s' => ['203.000', 'TND', 'fr', 'deux cent trois dinars'];
        yield 'mille is invariable' => ['2000.000', 'TND', 'fr', 'deux mille dinars'];
        yield 'mille alone is not un mille' => ['1000.000', 'TND', 'fr', 'mille dinars'];
        yield 'deux cent mille has no s before mille' => ['200000.000', 'TND', 'fr', 'deux cent mille dinars'];
        yield 'un million' => ['1000000.000', 'TND', 'fr', 'un million de dinars'];
        yield 'millions agree and take de' => ['2500000.000', 'TND', 'fr', 'deux millions cinq cent mille dinars'];
        yield 'a round million count takes de' => ['3000000.000', 'TND', 'fr', 'trois millions de dinars'];
        yield 'euros and centimes' => ['12.34', 'EUR', 'fr', 'douze euros et trente-quatre centimes'];
        yield 'english' => ['1234.578', 'TND', 'en', 'one thousand two hundred thirty-four dinars and five hundred seventy-eight millimes'];
        yield 'english singular' => ['1.010', 'TND', 'en', 'one dinar and ten millimes'];
        yield 'a negative amount is read as its absolute value' => ['-12.000', 'TND', 'fr', 'douze dinars'];
        yield 'a currency it has no words for' => ['12.000', 'XXX', 'fr', null];
        yield 'a language it has no words for' => ['12.000', 'TND', 'ar', null];
    }

    #[DataProvider('amounts')]
    public function testSpellsAnAmountOutInTheDocumentsLanguage(string $amount, string $currency, string $language, ?string $words): void
    {
        self::assertSame($words, AmountInWords::of($amount, $currency, $language));
    }
}

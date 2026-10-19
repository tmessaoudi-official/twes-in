<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Service\AmountInWords;
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
        yield 'a language it has no words for' => ['12.000', 'TND', 'de', null];
        yield 'arabic dinars and millimes' => ['1234.578', 'TND', 'ar', 'ألف ومائتان وأربعة وثلاثون دينارا وخمسمائة وثمانية وسبعون مليما'];
        yield 'arabic one says its noun first' => ['1.001', 'TND', 'ar', 'دينار واحد ومليم واحد'];
        yield 'arabic two is the dual alone' => ['2.002', 'TND', 'ar', 'ديناران ومليمان'];
        yield 'arabic three to ten count the plural' => ['3.010', 'TND', 'ar', 'ثلاثة دنانير وعشرة مليمات'];
        yield 'arabic eleven and more count the accusative singular' => ['11.012', 'TND', 'ar', 'أحد عشر دينارا واثنا عشر مليما'];
        yield 'arabic units come before tens' => ['21.000', 'TND', 'ar', 'واحد وعشرون دينارا'];
        yield 'arabic a round hundred counts the singular' => ['100.000', 'TND', 'ar', 'مائة دينار'];
        yield 'arabic two hundred loses its nun before the noun' => ['200.000', 'TND', 'ar', 'مائتا دينار'];
        yield 'arabic one after a hundred is said with its noun again' => ['101.000', 'TND', 'ar', 'مائة دينار ودينار'];
        yield 'arabic two after a hundred is the dual again' => ['102.000', 'TND', 'ar', 'مائة دينار وديناران'];
        yield 'arabic a hundred and three' => ['103.000', 'TND', 'ar', 'مائة وثلاثة دنانير'];
        yield 'arabic a hundred and eleven' => ['111.000', 'TND', 'ar', 'مائة وأحد عشر دينارا'];
        yield 'arabic a thousand' => ['1000.000', 'TND', 'ar', 'ألف دينار'];
        yield 'arabic two thousand before the noun' => ['2000.000', 'TND', 'ar', 'ألفا دينار'];
        yield 'arabic thousands three to ten' => ['3000.000', 'TND', 'ar', 'ثلاثة آلاف دينار'];
        yield 'arabic eleven thousand before the noun' => ['11000.000', 'TND', 'ar', 'أحد عشر ألف دينار'];
        yield 'arabic eleven thousand and more' => ['11250.000', 'TND', 'ar', 'أحد عشر ألفا ومائتان وخمسون دينارا'];
        yield 'arabic two hundred thousand' => ['200000.000', 'TND', 'ar', 'مائتا ألف دينار'];
        yield 'arabic a hundred and one thousand' => ['101000.000', 'TND', 'ar', 'مائة ألف وألف دينار'];
        yield 'arabic twelve hundred before the noun' => ['1200.000', 'TND', 'ar', 'ألف ومائتا دينار'];
        yield 'arabic a million' => ['1000000.000', 'TND', 'ar', 'مليون دينار'];
        yield 'arabic millions and thousands' => ['2500000.000', 'TND', 'ar', 'مليونان وخمسمائة ألف دينار'];
        yield 'arabic millions three to ten' => ['3000000.000', 'TND', 'ar', 'ثلاثة ملايين دينار'];
        yield 'arabic under a dinar says zero' => ['0.500', 'TND', 'ar', 'صفر دينار وخمسمائة مليم'];
        yield 'arabic zero' => ['0.000', 'TND', 'ar', 'صفر دينار'];
        yield 'arabic dollars and cents' => ['15.25', 'USD', 'ar', 'خمسة عشر دولارا وخمسة وعشرون سنتا'];
        yield 'arabic has no words for the euro yet' => ['12.00', 'EUR', 'ar', null];
    }

    #[DataProvider('amounts')]
    public function testSpellsAnAmountOutInTheDocumentsLanguage(string $amount, string $currency, string $language, ?string $words): void
    {
        self::assertSame($words, AmountInWords::of($amount, $currency, $language));
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\Service;

/**
 * An amount of money written out, as a printed invoice states it (« Arrêtée la présente facture à la somme de … »).
 * French follows the traditional spelling (hyphens only between a ten and its unit, « quatre-vingts » and « cents » taking
 * their s only when nothing follows them); English is the plain American one. Arabic is written as the subject of the
 * sentence it closes (« …قدره: »), so in the nominative: the counted noun follows the number's last part, one and two
 * after it (« دينار واحد », « ديناران »), three to ten its plural (« ثلاثة دنانير »), eleven to ninety-nine its singular in
 * the accusative (« أحد عشر دينارا »), and a round hundred, thousand or million its singular, the number then losing its
 * nunation (« مائتا دينار », « أحد عشر ألف دينار »); one or two after a hundred is said with its noun again (« مائة دينار
 * ودينار »), as « مائة وواحد » would read as a hundred and one of something else. A currency or a language it has no
 * words for gives null, so that nothing is printed rather than a wrong sentence.
 */
final class AmountInWords
{
    /** The languages it can count in: each one's grammar of numbers is code, as a new tax kind is. */
    private const string FRENCH = 'fr'; // language-literal: the French counting rules below are written for French alone
    private const string ENGLISH = 'en'; // language-literal: the English counting rules below are written for English alone
    private const string ARABIC = 'ar'; // language-literal: the Arabic counting rules below are written for Arabic alone

    /** @var array<string, array{fr: array{string, string, string, string}, en: array{string, string, string, string}, digits: int}> */
    private const CURRENCIES = [
        'TND' => [self::FRENCH => ['dinar', 'dinars', 'millime', 'millimes'], self::ENGLISH => ['dinar', 'dinars', 'millime', 'millimes'], 'digits' => 3],
        'EUR' => [self::FRENCH => ['euro', 'euros', 'centime', 'centimes'], self::ENGLISH => ['euro', 'euros', 'cent', 'cents'], 'digits' => 2],
        'USD' => [self::FRENCH => ['dollar', 'dollars', 'cent', 'cents'], self::ENGLISH => ['dollar', 'dollars', 'cent', 'cents'], 'digits' => 2],
    ];

    /**
     * Each currency's nouns in Arabic, for one, two, three to ten and eleven or more, the unit's and then its smallest
     * part's. The euro has none yet: « يورو » does not decline, and how an invoice counts it is not settled.
     *
     * @var array<string, array{array{string, string, string, string}, array{string, string, string, string}}>
     */
    private const AR_CURRENCIES = [
        'TND' => [['دينار', 'ديناران', 'دنانير', 'دينارا'], ['مليم', 'مليمان', 'مليمات', 'مليما']],
        'USD' => [['دولار', 'دولاران', 'دولارات', 'دولارا'], ['سنت', 'سنتان', 'سنتات', 'سنتا']],
    ];

    /** Thousands, millions and billions, counted as nouns are: one, two, three to ten, eleven or more. */
    private const AR_SCALES = [
        [1_000_000_000, ['مليار', 'ملياران', 'مليارات', 'مليارا']],
        [1_000_000, ['مليون', 'مليونان', 'ملايين', 'مليونا']],
        [1000, ['ألف', 'ألفان', 'آلاف', 'ألفا']],
    ];

    /** What a dual or an accusative becomes before the noun it counts (« ألفان » → « ألفا دينار »). */
    private const AR_CONSTRUCT = ['مائتان' => 'مائتا', 'ألفان' => 'ألفا', 'مليونان' => 'مليونا', 'ملياران' => 'مليارا', 'ألفا' => 'ألف', 'مليونا' => 'مليون', 'مليارا' => 'مليار'];

    /** One to nineteen as they count a masculine noun, dinars and millimes both: three to ten take the feminine ending. */
    private const AR_UNITS = ['صفر', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
    private const AR_TENS = [2 => 'عشرون', 3 => 'ثلاثون', 4 => 'أربعون', 5 => 'خمسون', 6 => 'ستون', 7 => 'سبعون', 8 => 'ثمانون', 9 => 'تسعون'];
    private const AR_HUNDREDS = [1 => 'مائة', 2 => 'مائتان', 3 => 'ثلاثمائة', 4 => 'أربعمائة', 5 => 'خمسمائة', 6 => 'ستمائة', 7 => 'سبعمائة', 8 => 'ثمانمائة', 9 => 'تسعمائة'];

    private const FR_UNITS = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
    private const FR_TENS = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];
    private const EN_UNITS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    private const EN_TENS = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];

    /** @param string $amount a decimal string, "1234.578"; its sign is ignored, and so is anything finer than the currency's smallest unit */
    public static function of(string $amount, string $currency, string $language): ?string
    {
        if (self::ARABIC === $language) {
            return self::arabic($amount, $currency);
        }
        $entry = self::CURRENCIES[$currency] ?? null;
        $names = null === $entry ? null : (self::FRENCH === $language ? $entry[self::FRENCH] : (self::ENGLISH === $language ? $entry[self::ENGLISH] : null));
        if (null === $entry || null === $names || !\in_array($language, [self::FRENCH, self::ENGLISH], true) || 1 !== preg_match('/^-?(\d{1,15})(?:\.(\d*))?$/', $amount, $parts)) {
            return null;
        }
        $digits = $entry['digits'];
        $whole = (int) $parts[1];
        $fraction = (int) str_pad(substr($parts[2] ?? '', 0, $digits), $entry['digits'], '0');

        $words = self::counted($whole, $names[0], $names[1], $language);
        if ($fraction > 0) {
            $words .= (self::FRENCH === $language ? ' et ' : ' and ').self::counted($fraction, $names[2], $names[3], $language);
        }

        return $words;
    }

    private static function arabic(string $amount, string $currency): ?string
    {
        $nouns = self::AR_CURRENCIES[$currency] ?? null;
        $entry = self::CURRENCIES[$currency] ?? null;
        if (null === $nouns || null === $entry || 1 !== preg_match('/^-?(\d{1,15})(?:\.(\d*))?$/', $amount, $parts)) {
            return null;
        }
        $whole = (int) $parts[1];
        $fraction = (int) str_pad(substr($parts[2] ?? '', 0, $entry['digits']), $entry['digits'], '0');

        $words = 0 === $whole ? self::AR_UNITS[0].' '.$nouns[0][0] : self::arabicCounted($whole, $nouns[0], true);
        if ($fraction > 0) {
            $words .= ' و'.self::arabicCounted($fraction, $nouns[1], true);
        }

        return $words;
    }

    /**
     * A number with the noun it counts, which takes the form its last part asks for.
     *
     * @param array{string, string, string, string} $noun  for one, two, three to ten, eleven or more
     * @param bool                                  $alone whether one is said « واحد » after the noun, as a sum is, or not, as « ألف » is
     */
    private static function arabicCounted(int $number, array $noun, bool $alone): string
    {
        $rest = $number % 100;

        return match (true) {
            1 === $number => $alone ? $noun[0].' '.self::AR_UNITS[1] : $noun[0],
            2 === $number => $noun[1],
            1 === $rest, 2 === $rest => self::arabicCounted($number - $rest, $noun, $alone).' و'.$noun[$rest - 1],
            0 === $rest => self::arabicConstruct(self::arabicNumber($number)).' '.$noun[0],
            default => self::arabicNumber($number).' '.($rest <= 10 ? $noun[2] : $noun[3]),
        };
    }

    private static function arabicNumber(int $number): string
    {
        $parts = [];
        foreach (self::AR_SCALES as [$size, $noun]) {
            $count = intdiv($number, $size) % 1000;
            if ($count > 0) {
                $parts[] = self::arabicCounted($count, $noun, false);
            }
        }
        if ($number % 1000 > 0) {
            $parts[] = self::arabicHundreds($number % 1000);
        }

        return implode(' و', $parts);
    }

    private static function arabicHundreds(int $number): string
    {
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;
        $tail = match (true) {
            0 === $rest => '',
            $rest < 20 => self::word(self::AR_UNITS, $rest),
            0 === $rest % 10 => self::word(self::AR_TENS, intdiv($rest, 10)),
            default => self::word(self::AR_UNITS, $rest % 10).' و'.self::word(self::AR_TENS, intdiv($rest, 10)),
        };
        if (0 === $hundreds) {
            return $tail;
        }
        $head = self::word(self::AR_HUNDREDS, $hundreds);

        return '' === $tail ? $head : $head.' و'.$tail;
    }

    /** The number's last word as it stands before the noun it counts. */
    private static function arabicConstruct(string $words): string
    {
        $cut = mb_strrpos($words, ' ');
        $head = false === $cut ? '' : mb_substr($words, 0, $cut + 1);
        $last = false === $cut ? $words : mb_substr($words, $cut + 1);
        // A last part after another carries its « و » joined to it (« ومائتان »).
        $and = str_starts_with($last, 'و') && isset(self::AR_CONSTRUCT[mb_substr($last, 1)]) ? 'و' : '';
        $word = mb_substr($last, mb_strlen($and));

        return $head.$and.(self::AR_CONSTRUCT[$word] ?? $word);
    }

    /**
     * @param array<array-key, string> $words
     */
    private static function word(array $words, int $index): string
    {
        return $words[$index] ?? throw new \LogicException(\sprintf('No word at %d.', $index));
    }

    private static function counted(int $number, string $singular, string $plural, string $language): string
    {
        if (self::ENGLISH === $language) {
            return self::english($number).' '.(1 === $number ? $singular : $plural);
        }

        // « un million de dinars »: a whole count of millions or more is followed by « de ».
        $of = $number >= 1_000_000 && 0 === $number % 1_000_000 ? ' de' : '';

        return self::french($number).$of.' '.($number >= 2 ? $plural : $singular);
    }

    private static function french(int $number): string
    {
        if (0 === $number) {
            return self::FR_UNITS[0];
        }
        $parts = [];
        foreach ([[1_000_000_000, 'milliard'], [1_000_000, 'million']] as [$size, $name]) {
            $count = intdiv($number, $size) % 1000;
            if ($count > 0) {
                $parts[] = self::frenchHundreds($count, true).' '.$name.($count > 1 ? 's' : '');
            }
        }
        $thousands = intdiv($number, 1000) % 1000;
        if ($thousands > 0) {
            $parts[] = 1 === $thousands ? 'mille' : self::frenchHundreds($thousands, false).' mille';
        }
        $rest = $number % 1000;
        if ($rest > 0) {
            $parts[] = self::frenchHundreds($rest, true);
        }

        return implode(' ', $parts);
    }

    /** @param bool $closing whether nothing follows it, which is what lets « cents » and « quatre-vingts » take their s */
    private static function frenchHundreds(int $number, bool $closing): string
    {
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;
        $tail = $rest > 0 ? self::frenchTens($rest, $closing) : '';
        if (0 === $hundreds) {
            return $tail;
        }
        $head = 1 === $hundreds ? 'cent' : self::word(self::FR_UNITS, $hundreds).' cent'.(0 === $rest && $closing ? 's' : '');

        return '' === $tail ? $head : $head.' '.$tail;
    }

    private static function frenchTens(int $number, bool $closing): string
    {
        if ($number < 20) {
            return self::word(self::FR_UNITS, $number);
        }
        $tens = intdiv($number, 10);
        $unit = $number % 10;
        if (7 === $tens) {
            return 1 === $unit ? 'soixante et onze' : 'soixante-'.self::word(self::FR_UNITS, 10 + $unit);
        }
        if (8 === $tens) {
            return 0 === $unit ? 'quatre-vingt'.($closing ? 's' : '') : 'quatre-vingt-'.self::word(self::FR_UNITS, $unit);
        }
        if (9 === $tens) {
            return 'quatre-vingt-'.self::word(self::FR_UNITS, 10 + $unit);
        }

        return match ($unit) {
            0 => self::word(self::FR_TENS, $tens),
            1 => self::word(self::FR_TENS, $tens).' et un',
            default => self::word(self::FR_TENS, $tens).'-'.self::word(self::FR_UNITS, $unit),
        };
    }

    private static function english(int $number): string
    {
        if (0 === $number) {
            return self::EN_UNITS[0];
        }
        $parts = [];
        foreach ([[1_000_000_000, 'billion'], [1_000_000, 'million'], [1000, 'thousand']] as [$size, $name]) {
            $count = intdiv($number, $size) % 1000;
            if ($count > 0) {
                $parts[] = self::englishHundreds($count).' '.$name;
            }
        }
        if ($number % 1000 > 0) {
            $parts[] = self::englishHundreds($number % 1000);
        }

        return implode(' ', $parts);
    }

    private static function englishHundreds(int $number): string
    {
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;
        $tail = match (true) {
            0 === $rest => '',
            $rest < 20 => self::word(self::EN_UNITS, $rest),
            0 === $rest % 10 => self::word(self::EN_TENS, intdiv($rest, 10)),
            default => self::word(self::EN_TENS, intdiv($rest, 10)).'-'.self::word(self::EN_UNITS, $rest % 10),
        };
        if (0 === $hundreds) {
            return $tail;
        }
        $head = self::word(self::EN_UNITS, $hundreds).' hundred';

        return '' === $tail ? $head : $head.' '.$tail;
    }
}

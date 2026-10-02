<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * An amount of money written out, as a printed invoice states it (« Arrêtée la présente facture à la somme de … »).
 * French follows the traditional spelling (hyphens only between a ten and its unit, « quatre-vingts » and « cents » taking
 * their s only when nothing follows them); English is the plain American one. A currency or a language it has no words
 * for gives null, so that nothing is printed rather than a wrong sentence.
 */
final class AmountInWords
{
    /** @var array<string, array{fr: array{string, string, string, string}, en: array{string, string, string, string}, digits: int}> */
    private const CURRENCIES = [
        'TND' => ['fr' => ['dinar', 'dinars', 'millime', 'millimes'], 'en' => ['dinar', 'dinars', 'millime', 'millimes'], 'digits' => 3],
        'EUR' => ['fr' => ['euro', 'euros', 'centime', 'centimes'], 'en' => ['euro', 'euros', 'cent', 'cents'], 'digits' => 2],
        'USD' => ['fr' => ['dollar', 'dollars', 'cent', 'cents'], 'en' => ['dollar', 'dollars', 'cent', 'cents'], 'digits' => 2],
    ];

    private const FR_UNITS = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
    private const FR_TENS = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];
    private const EN_UNITS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    private const EN_TENS = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];

    /** @param string $amount a decimal string, "1234.578"; its sign is ignored, and so is anything finer than the currency's smallest unit */
    public static function of(string $amount, string $currency, string $language): ?string
    {
        $entry = self::CURRENCIES[$currency] ?? null;
        $names = null === $entry ? null : ('fr' === $language ? $entry['fr'] : ('en' === $language ? $entry['en'] : null));
        if (null === $entry || null === $names || !\in_array($language, ['fr', 'en'], true) || 1 !== preg_match('/^-?(\d{1,15})(?:\.(\d*))?$/', $amount, $parts)) {
            return null;
        }
        $digits = $entry['digits'];
        $whole = (int) $parts[1];
        $fraction = (int) str_pad(substr($parts[2] ?? '', 0, $digits), $entry['digits'], '0');

        $words = self::counted($whole, $names[0], $names[1], $language);
        if ($fraction > 0) {
            $words .= ('fr' === $language ? ' et ' : ' and ').self::counted($fraction, $names[2], $names[3], $language);
        }

        return $words;
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
        if ('en' === $language) {
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

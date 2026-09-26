<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * `decimal`: a decimal string written the way a document's language writes numbers, from the string itself and never
 * through a float, so no amount loses a digit. It shows at least the given decimals and keeps any significant one
 * beyond them: a price of 12.3456 stays 12.3456 where the currency has three.
 *
 * `deduction`: an amount the document subtracts (a discount, a withholding), written with one sign: "−15,126" for an
 * invoice's 15.126, and "15,126" for a credit note's -15.126, which gives back what the invoice took (row 29).
 */
final class DecimalExtension extends AbstractExtension
{
    private const string DECIMAL = '/^(-?)(\d+)(?:\.(\d+))?$/';
    /** The decimal point and the thousands separator of each language; French groups with a no-break space. */
    private const array SEPARATORS = ['fr' => [',', "\u{a0}"], 'en' => ['.', ',']];
    /**
     * A company's chosen number format (`presentation.number-format`, docs/SPEC.md § 7, 2026-09-25 12:45, row 130), which
     * wins over the language; `auto` is the language's.
     */
    private const array STYLES = ['space-comma' => [',', "\u{a0}"], 'dot-comma' => [',', '.'], 'comma-dot' => ['.', ',']];
    /** A company's chosen date format (`presentation.date-format`), as a PHP date pattern; `auto` is the language's. */
    private const array DATE_PATTERNS = ['dmy' => 'd/m/Y', 'mdy' => 'm/d/Y', 'ymd' => 'Y-m-d', 'dmy-dots' => 'd.m.Y'];

    public function getFilters(): array
    {
        return [new TwigFilter('decimal', $this->format(...)), new TwigFilter('deduction', $this->deduction(...))];
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('date_pattern', $this->datePattern(...))];
    }

    /** The pattern a printed day is written with: the chosen order, else the language's own `$fallback`. */
    public function datePattern(string $style, string $fallback): string
    {
        return self::DATE_PATTERNS[$style] ?? $fallback;
    }

    public function format(string $value, int $minimumDecimals, string $language, string $style = 'auto'): string
    {
        if (1 !== preg_match(self::DECIMAL, $value, $parts)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a decimal.', $value));
        }
        [$point, $thousands] = self::STYLES[$style] ?? self::SEPARATORS[$language] ?? self::SEPARATORS['fr'];

        $integer = ltrim($parts[2], '0');
        $groups = array_reverse(str_split(strrev('' === $integer ? '0' : $integer), 3));
        $decimals = str_pad(rtrim($parts[3] ?? '', '0'), $minimumDecimals, '0');

        return $parts[1].implode($thousands, array_map(strrev(...), $groups)).('' === $decimals ? '' : $point.$decimals);
    }

    public function deduction(string $value, int $minimumDecimals, string $language, string $style = 'auto'): string
    {
        $written = $this->format(ltrim($value, '-'), $minimumDecimals, $language, $style);
        $zero = 1 !== preg_match('/[1-9]/', $value);

        return $zero || str_starts_with($value, '-') ? $written : '−'.$written;
    }
}

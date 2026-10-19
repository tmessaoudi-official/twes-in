<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `tax_label`: a tax as a document prints it, its name in the document's language with the rate the document froze.
 * Many names already say their rate (« TVA 19 % », as the presets write them), and those print as they are; any other
 * name gains the rate after it, « TVA (19 %) », so a renamed tax or one whose rate has changed since still shows what
 * this document charged. A name saying another rate keeps it, and the frozen one follows.
 */
final class TaxLabelExtension extends AbstractExtension
{
    private const string DECIMAL = '/^(\d+)(?:\.(\d+))?$/';

    public function __construct(private readonly DecimalExtension $decimals)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tax_label', $this->label(...))];
    }

    /** @param string $rate a percentage as a decimal string, `19.000` */
    public function label(string $name, string $rate, string $language, string $style = 'auto'): string
    {
        if (1 !== preg_match(self::DECIMAL, $rate, $parts)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a rate.', $rate));
        }
        $integer = ltrim($parts[1], '0');
        $decimals = rtrim($parts[2] ?? '', '0');
        // Zeros after the rate say the same rate: « 19,00 % », « 5,50 % ».
        $written = ('' === $integer ? '0' : $integer).('' === $decimals ? '(?:[.,]0+)?' : '[.,]'.$decimals.'0*');

        // The rate stands alone: no digit, point or comma before it (« 119 % », « 1,19 % »), and only spaces before
        // the sign, Latin or Arabic.
        if (1 === preg_match('/(?<![\d.,])'.$written.'[\s\x{a0}\x{202f}]*[%\x{066a}]/u', $name)) {
            return $name;
        }

        return \sprintf("%s (%s\u{a0}%%)", $name, $this->decimals->format($rate, 0, $language, $style));
    }
}

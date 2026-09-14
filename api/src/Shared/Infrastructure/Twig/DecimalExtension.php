<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `decimal`: a decimal string written the way a document's language writes numbers, from the string itself and never
 * through a float, so no amount loses a digit. It shows at least the given decimals and keeps any significant one
 * beyond them: a price of 12.3456 stays 12.3456 where the currency has three.
 */
final class DecimalExtension extends AbstractExtension
{
    private const string DECIMAL = '/^(-?)(\d+)(?:\.(\d+))?$/';
    /** The decimal point and the thousands separator of each language; French groups with a no-break space. */
    private const array SEPARATORS = ['fr' => [',', "\u{a0}"], 'en' => ['.', ',']];

    public function getFilters(): array
    {
        return [new TwigFilter('decimal', $this->format(...))];
    }

    public function format(string $value, int $minimumDecimals, string $language): string
    {
        if (1 !== preg_match(self::DECIMAL, $value, $parts)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a decimal.', $value));
        }
        [$point, $thousands] = self::SEPARATORS[$language] ?? self::SEPARATORS['fr'];

        $integer = ltrim($parts[2], '0');
        $groups = array_reverse(str_split(strrev('' === $integer ? '0' : $integer), 3));
        $decimals = str_pad(rtrim($parts[3] ?? '', '0'), $minimumDecimals, '0');

        return $parts[1].implode($thousands, array_map(strrev(...), $groups)).('' === $decimals ? '' : $point.$decimals);
    }
}

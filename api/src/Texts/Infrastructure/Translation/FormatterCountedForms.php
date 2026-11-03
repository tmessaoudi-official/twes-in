<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Infrastructure\Translation;

use App\Texts\Application\CountedForms;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\Formatter\MessageFormatterInterface;

/**
 * Writes the text through the formatter OverridingTranslator writes a changed text with, for every whole count it could
 * be handed, however large: Symfony refuses a count no interval of the text holds and no form of its language's
 * position stands for by throwing, which a mail would do at that count alone.
 *
 * Every count cannot be tried, and need not be: which interval holds a count changes only where a number written in
 * the text falls, and between two such places the form chosen depends only on the count's position in its language,
 * which every rule Symfony applies reads from the count's last two digits once past its first few. So from 0 and from
 * each number written (and the one after it), two hundred counts in a row are tried: every position each stretch
 * reaches, and every count above the largest number written.
 */
final readonly class FormatterCountedForms implements CountedForms
{
    /** How many counts in a row each stretch is tried for: twice the hundred a position repeats over. */
    private const int STRETCH = 200;
    /** A number written in an interval or a set, `{0,1}`, `[2,10]`, `]1,Inf[`, as Symfony reads them. */
    private const string WRITTEN = '/^\s*(?:\{\s*([-\d.\s,]+)\}|[\[\]]\s*(-Inf|-?\d+(?:\.\d+)?)\s*,\s*(\+?Inf|-?\d+(?:\.\d+)?)\s*[\[\]])/';

    public function __construct(
        #[Autowire(service: 'translator.formatter')] private MessageFormatterInterface $formatter,
    ) {
    }

    public function everyCountChooses(string $text, string $language): bool
    {
        $locale = str_replace('-', '_', $language);
        foreach (self::counts($text) as $count) {
            try {
                $this->formatter->format($text, $locale, ['%count%' => $count]);
            } catch (\InvalidArgumentException) {
                return false;
            }
        }

        return true;
    }

    /** @return list<int> 0 and each number written, each with the counts after it */
    private static function counts(string $text): array
    {
        $starts = [0 => true];
        foreach (preg_split('/(?<!\|)\|(?!\|)/', $text) ?: [] as $part) {
            if (1 !== preg_match(self::WRITTEN, $part, $written)) {
                continue;
            }
            $numbers = '' !== $written[1] ? explode(',', $written[1]) : [$written[2], $written[3]];
            // A whole count is all the code hands: a number's whole part, and the one after it, are where it changes.
            foreach ($numbers as $number) {
                if (1 === preg_match('/^\s*(\d+)/', $number, $whole)) {
                    $starts[(int) $whole[1]] = true;
                    $starts[(int) $whole[1] + 1] = true;
                }
            }
        }
        $counts = [];
        foreach (array_keys($starts) as $start) {
            for ($count = $start; $count < $start + self::STRETCH; ++$count) {
                $counts[$count] = $count;
            }
        }

        return array_values($counts);
    }
}

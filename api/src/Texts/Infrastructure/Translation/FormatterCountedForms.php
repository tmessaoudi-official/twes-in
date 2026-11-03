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
 * Writes the text through the formatter OverridingTranslator writes a changed text with, for counts that reach every
 * form a language has: none, one, two, a few, many and more (0, 1, 2, 5, 21, 100). Symfony refuses a count no form or
 * interval of the text holds by throwing, which a mail would do at send time.
 */
final readonly class FormatterCountedForms implements CountedForms
{
    private const array COUNTS = [0, 1, 2, 5, 21, 100];

    public function __construct(
        #[Autowire(service: 'translator.formatter')] private MessageFormatterInterface $formatter,
    ) {
    }

    public function everyCountChooses(string $text, string $language): bool
    {
        $locale = str_replace('-', '_', $language);
        foreach (self::COUNTS as $count) {
            try {
                $this->formatter->format($text, $locale, ['%count%' => $count]);
            } catch (\InvalidArgumentException) {
                return false;
            }
        }

        return true;
    }
}

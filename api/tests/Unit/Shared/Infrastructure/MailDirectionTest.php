<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * A mail in Arabic reads right to left in the reader's mail client, not only in a browser. Webmail clients show a
 * mail inside their own page and do not keep what the `<html>` element says, so the direction and the language are
 * said again on a block holding the whole mail. A value someone typed standing alone (the company's name, the
 * product's) takes its direction from itself, or a Latin name ending in a full stop moves it to the front; a sentence
 * in the mail's language keeps the mail's.
 */
final class MailDirectionTest extends TestCase
{
    /** What a person typed, a name in whatever script they wrote it, as opposed to a text the mail's language says. */
    private const array TYPED = ['companyName', 'productName'];

    public function testEveryMailSaysItsDirectionOnABlockHoldingAllOfIt(): void
    {
        $templates = $this->templates();
        foreach ($templates as $template) {
            $source = (string) file_get_contents($template);
            $name = basename($template);
            self::assertSame(1, preg_match('/<html lang="\{\{ ([\w.]+) \}\}" dir="\{\{ text_direction\(\1\) \}\}">/', $source, $html), "$name: the document names its language and direction");
            $locale = preg_quote($html[1] ?? '', '/');
            self::assertSame(
                1,
                preg_match('/<body[^>]*>\s*<div dir="\{\{ text_direction\('.$locale.'\) \}\}" lang="\{\{ '.$locale.' \}\}"[^>]*>.*<\/div>\s*<\/body>/s', $source),
                "$name: a block right inside the body, closing right before it, says the mail's direction and language again",
            );
        }

        self::assertGreaterThanOrEqual(9, \count($templates), 'the mails are found, so an empty set cannot pass');
    }

    public function testATypedValueStandingAloneReadsInItsOwnDirectionAndASentenceInTheMails(): void
    {
        $typed = 0;
        foreach ($this->templates() as $template) {
            $source = (string) file_get_contents($template);
            preg_match_all('/<([a-z][a-z0-9]*)\b([^>]*)>\s*\{\{ (\w+) \}\}\s*<\/\1>/', $source, $values, \PREG_SET_ORDER);
            foreach ($values as [, $tag, $attributes, $value]) {
                $own = 1 === preg_match('/\sdir="auto"/', $attributes);
                if (\in_array($value, self::TYPED, true)) {
                    ++$typed;
                    self::assertTrue($own, basename($template).": <$tag> holding {{ $value }} reads it in its own direction");
                } else {
                    self::assertFalse($own, basename($template).": <$tag> holding {{ $value }}, a text in the mail's language, reads in the mail's direction");
                }
            }
        }

        // The product's name closes every mail; the notification also names the company.
        self::assertGreaterThanOrEqual(10, $typed, 'the typed values are found, so an empty set cannot pass');
    }

    /** @return list<string> */
    private function templates(): array
    {
        return glob(\dirname(__DIR__, 4).'/templates/email/*.twig') ?: [];
    }
}

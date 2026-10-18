<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * A text the company typed (a line's description, the remarks, the late-payment text, the footer) is printed in the
 * language it was typed in, whatever the document's. Inside an Arabic document a French remark read right to left
 * moved its closing full stop to the front (« .Livrer au quai 3 », seen in Gotenberg): every such text takes its
 * direction from itself, a `<bdi>` inside a line or a block of its own with `dir="auto"`. This reads the templates;
 * what Chromium does with them was established by rendering, once.
 */
final class PdfTypedTextDirectionTest extends TestCase
{
    public function testEveryTypedTextOfSeveralLinesReadsInItsOwnDirection(): void
    {
        $templates = glob(\dirname(__DIR__, 4).'/templates/pdf/*.twig') ?: [];
        $found = 0;
        foreach ($templates as $template) {
            $source = (string) file_get_contents($template);
            preg_match_all('/\{\{[^}]*\|\s*nl2br\s*\}\}/', $source, $texts, \PREG_OFFSET_CAPTURE);
            foreach ($texts[0] as [$text, $at]) {
                ++$found;
                preg_match_all('/<([a-z][a-z0-9]*)\b([^>]*)>/', substr($source, 0, $at), $tags, \PREG_SET_ORDER);
                $holder = end($tags);
                self::assertNotFalse($holder, basename($template).": $text sits in an element");
                self::assertTrue(
                    'bdi' === $holder[1] || 1 === preg_match('/\sdir="auto"/', $holder[2]),
                    basename($template).": $text is held by <$holder[1]$holder[2]>, which reads it in the document's direction",
                );
            }
        }

        // Three documents print their lines, remarks and the company's notes; the invoice its late-payment text and footer.
        self::assertGreaterThanOrEqual(11, $found, 'the typed texts are found, so an empty set cannot pass');
    }

    /**
     * A telephone number holds no letter to set its direction, so on a page read right to left its groups of digits
     * are laid out from the right (« 000 000 74 216+ »): every one a document prints reads left to right, as it is dialled.
     */
    public function testEveryPrintedTelephoneNumberReadsLeftToRight(): void
    {
        $templates = glob(\dirname(__DIR__, 4).'/templates/pdf/*.twig') ?: [];
        $found = 0;
        foreach ($templates as $template) {
            $source = (string) file_get_contents($template);
            preg_match_all('/(<[a-z]+\b[^>]*>)\{\{\s*[a-z]+\.phone\s*\}\}/', $source, $phones, \PREG_SET_ORDER);
            foreach ($phones as [$written, $holder]) {
                ++$found;
                self::assertSame('<bdi dir="ltr">', $holder, basename($template).": $written");
            }
            self::assertSame(\count($phones), preg_match_all('/\.phone\s*\}\}/', $source), basename($template).' writes a telephone number outside an element of its own');
        }
        self::assertGreaterThanOrEqual(6, $found, 'the seller\'s on four documents and the buyer\'s and vendor\'s on the order to a vendor');
    }
}

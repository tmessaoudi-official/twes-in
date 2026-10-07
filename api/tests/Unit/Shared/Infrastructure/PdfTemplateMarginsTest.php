<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * The page margins of a printed document are GotenbergPdfRenderer's (0.4 inch on every side). A template whose `@page`
 * rule sets a margin overrides them in Chromium: `margin: 0` printed every delivery note and invoice flush against the
 * paper's edge, where a printer clips it (seen on Gotenberg 8.37, 2026-09-14). This reads the templates, it does not
 * render them: what the renderer does with the rule was established by rendering, once.
 */
final class PdfTemplateMarginsTest extends TestCase
{
    public function testNoPrintedDocumentSetsItsOwnPageMargins(): void
    {
        $templates = glob(\dirname(__DIR__, 4).'/templates/pdf/*.html.twig') ?: [];
        self::assertGreaterThanOrEqual(2, \count($templates), 'the delivery note and invoice layouts are found, so an empty set cannot pass');

        foreach ($templates as $template) {
            $name = basename($template);
            preg_match_all('/@page\s*\{([^}]*)\}/', (string) file_get_contents($template), $rules);
            self::assertNotSame([], $rules[1], "$name declares its page");
            foreach ($rules[1] as $rule) {
                self::assertStringContainsString('size: A4', $rule, "$name prints on A4");
                self::assertStringNotContainsString('margin', $rule, "$name leaves its page margins to the renderer");
            }
        }

        // A partial every document includes (its shared stylesheet and layouts) may not set them either.
        $partials = glob(\dirname(__DIR__, 4).'/templates/pdf/_*.twig') ?: [];
        self::assertNotSame([], $partials, 'the shared stylesheet is found');
        foreach ($partials as $partial) {
            preg_match_all('/@page\s*\{([^}]*)\}/', (string) file_get_contents($partial), $rules);
            self::assertSame([], array_filter($rules[1], static fn (string $rule): bool => str_contains($rule, 'margin')), basename($partial).' leaves the page margins to the renderer');
        }
    }
}

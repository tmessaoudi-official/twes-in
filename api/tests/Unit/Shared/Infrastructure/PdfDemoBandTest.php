<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * A demo's document says « DÉMO — sans valeur fiscale » on every page: every printed document draws the band's partial
 * as the first thing in its body, so a new document cannot leave it out. Twig's strict variables then require the
 * document's adapter to say whether its company is a demo, which DemoPdfTest renders for each.
 */
final class PdfDemoBandTest extends TestCase
{
    public function testEveryPrintedDocumentDrawsTheDemoBand(): void
    {
        $templates = array_filter(glob(\dirname(__DIR__, 4).'/templates/pdf/*.html.twig') ?: [], static fn (string $path): bool => !str_starts_with(basename($path), '_'));
        self::assertGreaterThanOrEqual(4, \count($templates), 'the invoice, quote, delivery note and statement are found, so an empty set cannot pass');

        foreach ($templates as $template) {
            self::assertMatchesRegularExpression(
                '/<body[^>]*>\s*\{%-? include \'pdf\/_demo_band\.html\.twig\' with \{demo: demo, t: t, locale: locale\} only -?%\}/',
                (string) file_get_contents($template),
                basename($template).' draws the demo band first in its body',
            );
        }
    }
}

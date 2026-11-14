<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\PdfRenderer;
use App\Shared\Application\PdfRenderingFailed;

/**
 * Requests reach no Gotenberg: this "PDF" is a PDF header followed by the page it was given, so a test reads what was
 * printed. The adapter's own contract is GotenbergPdfRendererTest's; the real renderer is driven by Playwright and by
 * FacturXTest's compliant case, which hands it the page this fake was given.
 */
final class FakePdfRenderer implements PdfRenderer
{
    /** @var list<string> the pages rendered, in order */
    public array $rendered = [];

    public bool $failing = false;

    public function render(string $html): string
    {
        if ($this->failing) {
            throw new PdfRenderingFailed('The fake renderer is failing.');
        }
        $this->rendered[] = $html;

        return "%PDF-1.7\n% rendered by the fake renderer\n".$html;
    }

    /** A PNG signature followed by the page, so a test reads what the picture shows. */
    public function firstPage(string $html): string
    {
        if ($this->failing) {
            throw new PdfRenderingFailed('The fake renderer is failing.');
        }
        $this->rendered[] = $html;

        return "\x89PNG\r\n\x1a\n".$html;
    }

    /** Each PDF after a marker, in order, so a test reads what was merged and in what order. */
    public function merge(array $pdfs): string
    {
        if ($this->failing) {
            throw new PdfRenderingFailed('The fake renderer is failing.');
        }

        return "%PDF-1.7\n% merged by the fake renderer\n".implode("\n% next\n", $pdfs);
    }
}

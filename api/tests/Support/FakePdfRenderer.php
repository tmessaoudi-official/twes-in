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
 * No test reaches Gotenberg: this "PDF" is a PDF header followed by the page it was given, so a test reads what was
 * printed. The adapter's own contract is GotenbergPdfRendererTest's, and the real renderer is driven by Playwright.
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
}

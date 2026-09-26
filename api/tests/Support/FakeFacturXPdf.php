<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\FacturXPdf;
use App\Shared\Application\PdfRenderingFailed;

/**
 * No test reaches Gotenberg: this Factur-X "PDF" is a PDF header followed by the XML it was given, and each call is
 * recorded. The adapter's own contract is GotenbergFacturXPdfTest's.
 */
final class FakeFacturXPdf implements FacturXPdf
{
    /** @var list<array{0: string, 1: string}> each PDF and XML embedded, in order */
    public array $embedded = [];

    public bool $failing = false;

    public function embed(string $pdf, string $ciiXml): string
    {
        if ($this->failing) {
            throw new PdfRenderingFailed('The fake Factur-X writer is failing.');
        }
        $this->embedded[] = [$pdf, $ciiXml];

        return "%PDF-1.7\n% Factur-X by the fake writer\n".$ciiXml;
    }
}

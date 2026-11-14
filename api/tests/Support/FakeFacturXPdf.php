<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\FacturXNotConformant;
use App\Shared\Application\FacturXPdf;
use App\Shared\Application\PdfRenderingFailed;

/**
 * Requests reach no Gotenberg: this Factur-X "PDF" is a PDF header followed by the XML it was given, and each call is
 * recorded. The adapter's own contract is GotenbergFacturXPdfTest's; FacturXTest's compliant case drives the real one.
 */
final class FakeFacturXPdf implements FacturXPdf
{
    /** @var list<array{0: string, 1: string}> each PDF and XML embedded, in order */
    public array $embedded = [];

    public bool $failing = false;

    /** Set, the file comes back with this many Factur-X or PDF/A-3 violations left, as Gotenberg counts them. */
    public ?int $leftViolations = null;

    public function embed(string $pdf, string $ciiXml): string
    {
        if ($this->failing) {
            throw new PdfRenderingFailed('The fake Factur-X writer is failing.');
        }
        if (null !== $this->leftViolations) {
            throw new FacturXNotConformant($this->leftViolations);
        }
        $this->embedded[] = [$pdf, $ciiXml];

        return "%PDF-1.7\n% Factur-X by the fake writer\n".$ciiXml;
    }
}

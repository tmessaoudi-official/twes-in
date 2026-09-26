<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * A PDF and its Cross Industry Invoice made one Factur-X file (docs/SPEC.md § 8 row 145): the PDF converted to PDF/A-3,
 * the XML embedded in it as `factur-x.xml` with the Alternative relationship, and the Factur-X XMP metadata written, at
 * the EN 16931 conformance level.
 */
interface FacturXPdf
{
    /**
     * @param string $pdf    the invoice's PDF, as it was issued
     * @param string $ciiXml its Cross Industry Invoice in the EN 16931 profile
     *
     * @return string the Factur-X PDF's bytes
     *
     * @throws PdfRenderingFailed
     */
    public function embed(string $pdf, string $ciiXml): string;
}

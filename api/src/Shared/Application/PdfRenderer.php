<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/** HTML in, PDF out (docs/SPEC.md § 2 PDF: a Gotenberg container, Twig HTML in), or its first page as a picture. */
interface PdfRenderer
{
    /**
     * @param string $html a whole page, its styles inline
     *
     * @return string the PDF's bytes
     *
     * @throws PdfRenderingFailed
     */
    public function render(string $html): string;

    /**
     * The page's first sheet as a PNG picture, as the PDF would print it, for a screen to show while a design is chosen.
     *
     * @param string $html a whole page, its styles inline
     *
     * @return string the PNG's bytes
     *
     * @throws PdfRenderingFailed
     */
    public function firstPage(string $html): string;
}

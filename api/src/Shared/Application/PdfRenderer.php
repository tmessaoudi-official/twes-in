<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/** HTML in, PDF out (docs/SPEC.md § 2 PDF: a Gotenberg container, Twig HTML in). */
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
}

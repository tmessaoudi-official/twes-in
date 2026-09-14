<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/** The renderer could not be reached, refused the page, or answered something other than a PDF. */
final class PdfRenderingFailed extends \RuntimeException
{
}

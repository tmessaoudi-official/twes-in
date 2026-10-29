<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * The sheet every document prints on: A4 as the renderer hands it to Chromium, in inches, and the margin it leaves on
 * every side. The renderer sends these figures, and whatever is fitted to a page (a watermark's word) is fitted to what
 * they leave, so the two cannot drift apart.
 */
final class PrintedPage
{
    /** A4 in inches, as Chromium's print takes it (210 × 297 mm, rounded as Gotenberg documents A4). */
    public const float WIDTH_IN = 8.27;
    public const float HEIGHT_IN = 11.7;

    /** The margin on every side, in inches: the templates leave theirs to it (PdfTemplateMarginsTest). */
    public const float MARGIN_IN = 0.4;

    /** What a sheet leaves its content inside the margins, in points (72 an inch). */
    public const float CONTENT_WIDTH_PT = (self::WIDTH_IN - 2 * self::MARGIN_IN) * 72;
    public const float CONTENT_HEIGHT_PT = (self::HEIGHT_IN - 2 * self::MARGIN_IN) * 72;

    private function __construct()
    {
    }
}

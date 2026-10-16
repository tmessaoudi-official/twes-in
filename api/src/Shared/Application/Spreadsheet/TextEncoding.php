<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Spreadsheet;

/**
 * What a CSV's bytes are written in, of those the tools a company uses write: UTF-8 from a web application, Windows-1252
 * from French Excel, Windows-1256 from Excel in Arabic, UTF-16 from Excel's « Texte Unicode ». A workbook has none: its
 * text is UTF-8 inside the zip whatever wrote it.
 */
enum TextEncoding: string
{
    case Utf8 = 'utf-8';
    case Windows1252 = 'windows-1252';
    case Windows1256 = 'windows-1256';
    case Utf16 = 'utf-16';
}

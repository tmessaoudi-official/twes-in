<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Notation;

/**
 * Which mark a company's CSV files write decimals with (import.decimal_mark). Automatic reads a point or a comma as the
 * decimal mark, as French and Tunisian spreadsheets write either, and groups thousands only with spaces; a mark named
 * reads the other one as thousands set apart only beyond doubt, and refuses it anywhere else (ImportCells::decimal).
 */
enum DecimalMark: string
{
    case Automatic = 'auto';
    case Point = 'point';
    case Comma = 'comma';
}

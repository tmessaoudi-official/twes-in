<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Spreadsheet;

/** The two formats a business already keeps its data in (docs/SPEC.md § 7, 2026-09-17): CSV and Excel's .xlsx. */
enum SpreadsheetFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    /** What the file is called, and what a person's own file has to be named to be recognised. */
    public function extension(): string
    {
        return $this->value;
    }

    public function mediaType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    /** The format a file's own name claims, or none when it claims something we do not read. */
    public static function ofFilename(string $filename): ?self
    {
        return self::tryFrom(strtolower(pathinfo($filename, \PATHINFO_EXTENSION)));
    }
}

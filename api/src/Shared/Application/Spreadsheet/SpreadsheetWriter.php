<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Spreadsheet;

/**
 * Rows out to a file (docs/SPEC.md § 7, import and export): the templates a person downloads to fill in, and the
 * export of a list with the filters it shows.
 *
 * Cells go out as text, as they come in: an amount is already a decimal string everywhere in this codebase, and
 * handing it to a spreadsheet as a number would round-trip it through a float on the way out.
 */
interface SpreadsheetWriter
{
    /**
     * @param string                      $path the file to create, its directory existing
     * @param iterable<int, list<string>> $rows written as they arrive, so an export of a long list never holds more
     *                                          than one row in memory
     *
     * @throws UnwritableSpreadsheet when the file cannot be written
     */
    public function write(string $path, SpreadsheetFormat $format, iterable $rows): void;
}

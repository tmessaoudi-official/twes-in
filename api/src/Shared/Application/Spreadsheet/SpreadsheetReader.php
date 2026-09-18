<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Spreadsheet;

/**
 * A spreadsheet's rows, as text (docs/SPEC.md § 7, import and export).
 *
 * Every cell arrives as a string because an importer parses from text: a price becomes a decimal through the same
 * parser whether it was typed into a CSV or stored as a number by Excel, and no amount passes through a float this
 * code did not choose. A date cell becomes an ISO date for the same reason.
 */
interface SpreadsheetReader
{
    /**
     * Only the first sheet is read: a template has one, and a person's own file is asked to put the data in the first.
     *
     * @param string $path a readable LOCAL file — .xlsx is a zip archive, which has to be seekable, so a stored file
     *                     is copied to a temporary one before it is read
     *
     * @return iterable<int, list<string>> each row keyed by its OWN line number in the file, empty rows included, so
     *                                     that a rejected row can be named by the line its author will look at
     *
     * @throws UnreadableSpreadsheet when the file is not a spreadsheet of that format at all
     */
    public function rows(string $path, SpreadsheetFormat $format): iterable;
}

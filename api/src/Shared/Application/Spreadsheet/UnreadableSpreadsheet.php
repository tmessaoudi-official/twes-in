<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Spreadsheet;

/**
 * The file is not a spreadsheet of the format it claims: a .xlsx that is not a workbook, a file that is not there.
 *
 * This is the whole FILE being refused, never one of its rows. A row that cannot be made sense of is an outcome the
 * import preview reports with its reason and its line number, not an exception. `reason` is the code a screen words.
 */
final class UnreadableSpreadsheet extends \RuntimeException
{
    /** Not a spreadsheet of the format its name says. */
    public const string UNREADABLE = 'unreadable';
    /** A workbook part declares a document type, which no spreadsheet application writes: refused before it is parsed. */
    public const string DOCUMENT_TYPE = 'xml_doctype';

    public function __construct(string $message = '', ?\Throwable $previous = null, public readonly string $reason = self::UNREADABLE)
    {
        parent::__construct($message, 0, $previous);
    }
}

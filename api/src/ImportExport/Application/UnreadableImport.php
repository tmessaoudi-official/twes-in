<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * A file refused whole, before any row is imported: what is wrong with it, as a code a screen translates, and the
 * columns concerned.
 */
final class UnreadableImport extends \RuntimeException
{
    /** Not a spreadsheet of the format its name says. */
    public const string UNREADABLE = 'unreadable';
    /** No header, or a header and no row. */
    public const string EMPTY = 'empty';
    /** Header cells that are not a column of the subject: an importer that guesses would put data in the wrong place. */
    public const string UNKNOWN_COLUMNS = 'unknown_columns';
    /** Required columns the header lacks. */
    public const string MISSING_COLUMNS = 'missing_columns';
    /** A column the header names twice: which of the two to read would be a guess. */
    public const string DUPLICATE_COLUMNS = 'duplicate_columns';
    /** More rows than one import takes. */
    public const string TOO_MANY_ROWS = 'too_many_rows';
    /** Headings paired with something that is no column of the subject, named as the file writes them. */
    public const string INVALID_PAIRING = 'invalid_pairing';
    /** A header the encoding the file is read in cannot read: no column can be named, so none is. */
    public const string UNREADABLE_ENCODING = 'unreadable_encoding';

    /** @param list<string> $columns */
    public function __construct(
        public readonly string $reason,
        public readonly array $columns = [],
        public readonly ?int $limit = null,
    ) {
        parent::__construct([] === $columns ? $reason : \sprintf('%s: %s', $reason, implode(', ', $columns)));
    }
}

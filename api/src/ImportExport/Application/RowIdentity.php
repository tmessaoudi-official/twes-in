<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * What one row names itself by, for finding the SAME thing twice in one file: the second row naming it is rejected
 * before it reaches the subject, naming the first. `$column` is the cell the refusal points at.
 */
final readonly class RowIdentity
{
    public function __construct(
        public string $column,
        public string $key,
        /** How the refusal says what both rows name, in words: « number », « reference and location_code ». */
        public string $named,
    ) {
    }

    /**
     * The identity read from these columns as written. A row that leaves any of them empty has no identity at all —
     * it is rejected by the subject for the missing value, which says more than "a duplicate of the other row that is
     * also empty" would.
     *
     * The parts are joined on a separator no cell can hold, so a pair like ("VIS-6", "A-12") can never collide with
     * ("VIS", "6A-12").
     *
     * @param non-empty-list<string> $columns
     */
    public static function ofColumns(ImportRecord $record, array $columns): ?self
    {
        $parts = [];
        foreach ($columns as $column) {
            $value = $record->value($column);
            if (null === $value) {
                return null;
            }
            $parts[] = $value;
        }

        return new self($columns[0], implode("\x1f", $parts), implode(' and ', $columns));
    }
}

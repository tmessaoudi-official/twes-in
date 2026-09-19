<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * One row of an import file, under the column keys of its header: only the columns the file has, each cell trimmed.
 * A column the file lacks and a cell it leaves blank read the same, as absent, which is what lets a partial file update
 * a few fields without clearing the rest (docs/SPEC.md § 7, 2026-09-19).
 */
final readonly class ImportRecord
{
    /** @param array<string, string> $values trimmed cells by column key */
    public function __construct(
        public int $line,
        public array $values,
    ) {
    }

    /** The cell, or null when the file has no such column or leaves it blank. */
    public function value(string $key): ?string
    {
        $value = $this->values[$key] ?? '';

        return '' === $value ? null : $value;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * What one kind of thing looks like when it is imported: customers, products, then vendors and opening stock
 * (docs/SPEC.md § 7, 2026-09-17).
 *
 * A subject is built FOR a company, never once for the application, because two of its columns are the company's own:
 * the registration numbers its fiscal preset asks for, and the custom fields it has defined. A template shared by
 * every company would either omit them or invent them.
 */
final readonly class ImportSubject
{
    /** @param list<ImportColumn> $columns in the order the template writes them */
    public function __construct(
        public string $key,
        public array $columns,
    ) {
    }

    /** @return list<string> the template's header row, and what a file's own header is matched against */
    public function keys(): array
    {
        return array_map(static fn (ImportColumn $column): string => $column->key, $this->columns);
    }

    public function column(string $key): ?ImportColumn
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }
}

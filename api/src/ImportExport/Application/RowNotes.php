<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * What a subject tells the person about one row it imported without refusing it: the reference it gave a product, a
 * name another product already holds. Each note is a stable code and its parameters, translated by the screen as
 * `import.notes.<code>`; a row that ends up rejected keeps none, since its rejection says what matters.
 */
final class RowNotes
{
    /** @var list<array{column: string|null, code: string, params: array<string, string|int>}> */
    private array $notes = [];

    /** @var list<array{column: string, before: string, after: string}> */
    private array $changes = [];

    /** @param array<string, string|int> $params */
    public function note(?string $column, string $code, array $params = []): void
    {
        $this->notes[] = ['column' => $column, 'code' => $code, 'params' => $params];
    }

    /** @return list<array{column: string|null, code: string, params: array<string, string|int>}> */
    public function all(): array
    {
        return $this->notes;
    }

    /**
     * What a row found again changes, cell by cell, as the file writes each value (docs/SPEC.md § 7, 2026-10-09 10:40,
     * ruling 5): every column whose text differs between the two is kept, old and new.
     *
     * @param array<string, string> $before by column
     * @param array<string, string> $after  by column
     *
     * @return bool whether anything differs
     */
    public function changedBetween(array $before, array $after): bool
    {
        $any = false;
        foreach ($after as $column => $value) {
            $was = $before[$column] ?? '';
            if ($was !== $value) {
                $this->changes[] = ['column' => $column, 'before' => $was, 'after' => $value];
                $any = true;
            }
        }

        return $any;
    }

    /** @return list<array{column: string, before: string, after: string}> */
    public function changes(): array
    {
        return $this->changes;
    }
}

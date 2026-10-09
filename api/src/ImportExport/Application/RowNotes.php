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
}

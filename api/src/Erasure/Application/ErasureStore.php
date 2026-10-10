<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

use Symfony\Component\Uid\Uuid;

/** Takes the rows a part declares, keeps them as a copy, puts them back, and lets the copy go. */
interface ErasureStore
{
    /**
     * How many rows the steps would take now, by part and by the word they count under.
     *
     * @param list<ErasedRows>       $steps
     * @param list<ErasureReference> $references
     *
     * @return array<string, array<string, int>>
     */
    public function count(Uuid $companyId, array $steps, array $references): array;

    /**
     * Takes the rows at once, copying each under the erasure as it goes, and empties the links that named them.
     *
     * @param list<ErasedRows>       $steps
     * @param list<ErasureReference> $references
     *
     * @return array<string, array<string, int>> how many went, as count() says it
     */
    public function erase(Uuid $companyId, Uuid $erasureId, array $steps, array $references): array;

    /**
     * Puts every copied row back and fills the links again, all or nothing, and lets the copy go.
     *
     * @param list<ErasureReference> $references
     *
     * @throws ErasureConflict when something made since stands in a row's way
     */
    public function restore(Uuid $companyId, Uuid $erasureId, array $references): void;

    /**
     * Deletes the copy, and says which stored files only it still named.
     *
     * @param list<NamedFile> $files
     *
     * @return list<Uuid>
     */
    public function forget(Uuid $companyId, Uuid $erasureId, array $files): array;
}

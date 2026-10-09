<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * What an import did, or would do: the lines created, updated and rejected, by the file's own line numbers, and what
 * the rows imported were noted for, and whether this very file was imported before.
 */
final readonly class ImportReport
{
    /**
     * @param list<int>                                                                                                     $created
     * @param list<int>                                                                                                     $updated
     * @param list<array{line: int, column: string|null, code: string, params: array<string, string|int>, message: string}> $rejected
     * @param list<array{line: int, column: string|null, code: string, params: array<string, string|int>}>                  $notes
     */
    public function __construct(
        public bool $committed,
        public array $created,
        public array $updated,
        public array $rejected,
        public array $notes = [],
        /** When this very file was last imported into the subject, committed; null when it never was. */
        public ?\DateTimeImmutable $alreadyImportedAt = null,
    ) {
    }
}

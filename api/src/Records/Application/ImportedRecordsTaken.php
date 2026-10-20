<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Records\Domain\Model\RecordRemoval;

/**
 * What « Annuler cet import » did with the records of one subject the import created: those nothing used went to the
 * bin under one removal, the others stayed, each with what names it.
 */
final readonly class ImportedRecordsTaken
{
    /**
     * @param list<array{id: string, label: string}>                                                               $removed
     * @param list<array{id: string, label: string, uses: list<array{table: string, column: string, count: int}>}> $kept
     */
    public function __construct(
        public ?RecordRemoval $removal,
        public array $removed,
        public array $kept,
    ) {
    }
}

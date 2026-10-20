<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Undo;

use Symfony\Component\Uid\Uuid;

/**
 * What « Annuler cet import » did: the records the file created that nothing used went to the bin under `removal`,
 * those something used since stayed, each with what names it; the rows it created that are no record a person deletes
 * (a product's link to a vendor) went too, or stayed with why; the stock it brought was taken back where nothing came
 * to stand on it. What the file only changed stays as it was changed, `updated` of them.
 */
final readonly class ImportUndone
{
    /**
     * @param list<array{id: string, label: string}>                                                               $removed
     * @param list<array{id: string, label: string, uses: list<array{table: string, column: string, count: int}>}> $kept
     * @param list<array{label: string, reason: string}>                                                           $rowsKept
     * @param list<array{reference: string, location: string, quantity: string}>                                   $stockTakenBack
     * @param list<array{reference: string, location: string, reason: string}>                                     $stockKept
     */
    public function __construct(
        public ?Uuid $removal,
        public array $removed,
        public array $kept,
        public array $rowsKept,
        public array $stockTakenBack,
        public array $stockKept,
        public int $updated,
    ) {
    }
}

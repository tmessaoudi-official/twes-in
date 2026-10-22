<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

/**
 * A kind of record a person may delete while nothing uses it, restore, and merge: its table, which its references name,
 * the permission that writes it, which restoring needs, and the one deleting and merging need, the same unless the
 * subject keeps removing apart, the module it belongs to, switched off with it, and
 * the columns that name one record to a person (« F-001 Sotumag »), joined by a space where they hold something.
 * It is merged only once it says how: as an alias, switched off (`is_active`) and pointing at the record kept
 * (`merged_into_id`), which its table must hold, or removed. One that says nothing is not merged, and a merge of it is
 * answered as one of records the company does not keep. Records holding different values in a column of `$mustAgree`
 * are not one record and are not merged; for a JSON object, the keys both hold.
 *
 * Only the rows whose `$whereColumn` holds one of `$whereValues` are the subject's records (a draft): any other is
 * answered as one the company does not keep, and stays. A record whose own columns may all be empty (a draft has no
 * number yet) is named also by columns of the row its `$labelKey` names in `$labelTable` (its customer's number and name),
 * and, one step further, by columns of the row that row's `$labelNextKey` names in `$labelNextTable`: a recurring invoice
 * by its model's number and the customer the model bills. One with no column of its own worth reading is named by those
 * alone.
 */
final readonly class RecordSubject
{
    /** What deleting and merging its records needs: the permission that writes them, unless a subject keeps removing apart. */
    public string $removePermission;

    /**
     * @param list<string> $labelColumns
     * @param list<string> $mustAgree
     * @param list<string> $whereValues
     * @param list<string> $labelTableColumns
     * @param list<string> $labelNextColumns
     */
    public function __construct(
        public string $table,
        public string $writePermission,
        public ?string $module,
        public array $labelColumns,
        public ?MergedAway $mergedAway = null,
        public array $mustAgree = [],
        public ?string $whereColumn = null,
        public array $whereValues = [],
        public ?string $labelTable = null,
        public ?string $labelKey = null,
        public array $labelTableColumns = [],
        ?string $removePermission = null,
        public ?string $labelNextTable = null,
        public ?string $labelNextKey = null,
        public array $labelNextColumns = [],
    ) {
        $this->removePermission = $removePermission ?? $writePermission;
        if ((null === $whereColumn) !== ([] === $whereValues)) {
            throw new \InvalidArgumentException(\sprintf('%s: the rows that are its records are named by a column and at least one of its values.', $table));
        }
        if ((null === $labelTable) !== (null === $labelKey) || (null === $labelTable) !== ([] === $labelTableColumns)) {
            throw new \InvalidArgumentException(\sprintf('%s: a label read from another table names that table, the column leading to it and its columns.', $table));
        }
        if ((null === $labelNextTable) !== (null === $labelNextKey) || (null === $labelNextTable) !== ([] === $labelNextColumns)) {
            throw new \InvalidArgumentException(\sprintf('%s: a label read one step further names that table, the column leading to it and its columns.', $table));
        }
        if (null !== $labelNextTable && null === $labelTable) {
            throw new \InvalidArgumentException(\sprintf('%s: a label is read one step further only from the table it is read from first.', $table));
        }
        if ([] === $labelColumns && null === $labelTable) {
            throw new \InvalidArgumentException(\sprintf('%s: a record is named by columns of its own or of another table.', $table));
        }
        if (null !== $whereColumn && null !== $mergedAway) {
            throw new \InvalidArgumentException(\sprintf('%s: a subject only some rows of which are records is not merged.', $table));
        }
        foreach ([$table, ...$labelColumns, ...$mustAgree, $whereColumn ?? 'none', $labelTable ?? 'none', $labelKey ?? 'none', ...$labelTableColumns, $labelNextTable ?? 'none', $labelNextKey ?? 'none', ...$labelNextColumns] as $name) {
            if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a name the records engine may write into SQL.', $name));
            }
        }
    }
}

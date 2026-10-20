<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

/**
 * A kind of record a person may delete while nothing uses it, restore, and merge: its table, which its references name,
 * the permission that writes it, which deleting and restoring need, the module it belongs to, switched off with it, and
 * the columns that name one record to a person (« F-001 Sotumag »), joined by a space where they hold something.
 * It is merged only once it says how: as an alias, switched off (`is_active`) and pointing at the record kept
 * (`merged_into_id`), which its table must hold, or removed. One that says nothing is not merged, and a merge of it is
 * answered as one of records the company does not keep. Records holding different values in a column of `$mustAgree`
 * are not one record and are not merged; for a JSON object, the keys both hold.
 */
final readonly class RecordSubject
{
    /**
     * @param non-empty-list<string> $labelColumns
     * @param list<string>           $mustAgree
     */
    public function __construct(public string $table, public string $writePermission, public ?string $module, public array $labelColumns, public ?MergedAway $mergedAway = null, public array $mustAgree = [])
    {
        foreach ([$table, ...$labelColumns, ...$mustAgree] as $name) {
            if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a name the records engine may write into SQL.', $name));
            }
        }
    }
}

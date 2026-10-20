<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

/**
 * A kind of record a person may delete while nothing uses it, restore, and merge: its table, which its references name,
 * and the permission that writes it, which deleting and restoring need.
 */
final readonly class RecordSubject
{
    public function __construct(public string $table, public string $writePermission)
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a name the records engine may write into SQL.', $table));
        }
    }
}

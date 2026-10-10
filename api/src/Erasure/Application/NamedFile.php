<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

/**
 * A column naming a stored file in rows a part takes: at the erasure's end, a file that only the copy still names is
 * deleted with its content; one that a row still standing names is left alone.
 */
final readonly class NamedFile
{
    public function __construct(public string $table, public string $column)
    {
        foreach ([$table, $column] as $name) {
            if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a name the erasure may write into SQL.', $name));
            }
        }
    }
}

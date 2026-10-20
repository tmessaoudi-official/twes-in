<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application\Merge;

/** A row the merge would point at the record kept, which a unique index then refuses, as twins none was declared for. */
final class MergeConflict extends \RuntimeException
{
    public function __construct(public readonly string $table, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('A row of %s would stand twice beside the record kept.', $table), 0, $previous);
    }
}

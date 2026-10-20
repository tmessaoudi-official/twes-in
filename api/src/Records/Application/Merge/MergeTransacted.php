<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application\Merge;

/** The record kept traded since the merge: its undo would rewrite what is now in the books or the stock. */
final class MergeTransacted extends \RuntimeException
{
    /** @param non-empty-list<array{table: string, count: int}> $uses what names the record kept since the merge */
    public function __construct(public readonly array $uses)
    {
        parent::__construct('The record kept has traded since the merge, which is no longer undone.');
    }
}

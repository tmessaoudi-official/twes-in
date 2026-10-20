<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

/** Records a row still names: none of the records asked for goes. */
final class RecordsInUse extends \RuntimeException
{
    /** @param non-empty-list<array{id: string, label: string, uses: non-empty-list<array{table: string, column: string, count: int}>}> $records */
    public function __construct(public readonly array $records)
    {
        parent::__construct(\sprintf('%d of the records are still named elsewhere: deactivate or merge them instead.', \count($records)));
    }
}

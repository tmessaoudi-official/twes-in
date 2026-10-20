<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

/** A restore something made since stands in the way of: another record given the same number, for one. */
final class RemovalConflict extends \RuntimeException
{
    public function __construct(public readonly string $table, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('A row of %s made since stands in the way of the restore.', $table), 0, $previous);
    }
}

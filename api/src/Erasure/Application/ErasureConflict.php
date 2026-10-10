<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

/**
 * Something made since the erasure stands where a row would come back (the level a new floor took, a row it names that
 * is gone): the undo puts back all or nothing, and names the table where it stopped.
 */
final class ErasureConflict extends \DomainException
{
    public function __construct(public readonly string $table, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('A row of %s cannot come back: something made since stands in its way.', $table), 0, $previous);
    }
}

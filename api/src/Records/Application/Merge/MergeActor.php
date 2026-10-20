<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application\Merge;

use Symfony\Component\Uid\Uuid;

/**
 * Who merges, and what else they may do: a merge that writes what only some may write (the goods a product held move
 * what the product kept is worth, a cost) asks here for that right, so merging takes no permission of its own.
 */
final readonly class MergeActor
{
    /** @param \Closure(string): bool $may whether the person holds the permission in the company merged in */
    public function __construct(public Uuid $id, private \Closure $may)
    {
    }

    public function may(string $permission): bool
    {
        return ($this->may)($permission);
    }
}

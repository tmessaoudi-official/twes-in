<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/** Which page of a list is wanted, numbered from 1, and how many rows a page holds (docs/SPEC.md § 7, lists at scale). */
final readonly class PageRequest
{
    public function __construct(public int $page, public int $size)
    {
        if ($page < 1 || $size < 1) {
            throw new \InvalidArgumentException('A page is numbered from 1 and holds at least one row.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->size;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * One page of a list and how many rows the whole list holds, as a repository answers it.
 *
 * @template T
 */
final readonly class Page
{
    /** @param list<T> $items */
    public function __construct(public array $items, public int $total, public PageRequest $request)
    {
    }

    /**
     * @template R
     *
     * @param callable(T): R $map
     *
     * @return Page<R>
     */
    public function map(callable $map): self
    {
        return new self(array_map($map, $this->items), $this->total, $this->request);
    }
}

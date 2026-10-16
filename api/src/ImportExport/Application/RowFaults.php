<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * Every fault of one row, gathered while its cells are read (docs/SPEC.md § 7, 2026-10-09 10:40, ruling 9): a person
 * fixing a file reads them all at once rather than one per try. A cell refused leaves its reading to an answer that
 * stands in for it, so the rest of the row is still read; nothing is written while the row holds a fault.
 */
final class RowFaults
{
    /** @var list<RowRejected> */
    private array $faults = [];

    /**
     * One cell's reading: its value, or `$instead` when the cell is refused, the refusal kept.
     *
     * @template T
     * @template U
     *
     * @param callable(): T $reading
     * @param U             $instead
     *
     * @return T|U
     */
    public function read(callable $reading, mixed $instead = null): mixed
    {
        try {
            return $reading();
        } catch (RowRejected $refused) {
            array_push($this->faults, ...$refused->all());

            return $instead;
        }
    }

    /**
     * Stops the row when a cell was refused, with every refusal kept.
     *
     * @throws RowRejected
     */
    public function stopIfAny(): void
    {
        if ([] !== $this->faults) {
            throw RowRejected::together($this->faults);
        }
    }
}

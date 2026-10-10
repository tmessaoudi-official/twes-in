<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

/**
 * Rows a part of « Effacer des données » takes from one table: those of the company its condition picks, or, for rows
 * that belong to others, those whose column names one of the rows taken above them. Every row a cascade would take with
 * them is a step of its own, so that the copy holds it (ErasureCoverage checks it against the database).
 *
 * The condition is SQL over the table's own columns, written by the module that owns the table; the erasure adds the
 * company itself, so no condition may leave it out.
 */
final readonly class ErasedRows
{
    private function __construct(
        public string $part,
        public string $table,
        public string $where,
        public ?self $parent,
        public ?string $parentColumn,
        public ?string $counted,
        public ?string $live,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $table) || (null !== $parentColumn && 1 !== preg_match('/^[a-z][a-z0-9_]*$/', $parentColumn))) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a table name and column the erasure may write into SQL.', $table));
        }
    }

    /**
     * @param string  $counted the preview's word for these rows, which several steps may share (« brouillons »)
     * @param ?string $live    the kind open screens reload on when these rows go or come back
     */
    public static function of(string $part, string $table, string $where, ?string $counted = null, ?string $live = null): self
    {
        return new self($part, $table, $where, null, null, $counted, $live);
    }

    /** The rows of a table whose column names one of these rows, and that go with them. */
    public function child(string $table, string $column, string $where = 'true', ?string $counted = null, ?string $live = null): self
    {
        return new self($this->part, $table, $where, $this, $column, $counted, $live);
    }
}

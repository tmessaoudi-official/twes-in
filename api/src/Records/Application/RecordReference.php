<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

/**
 * A column that names a subject's records, declared by the module owning the column, through a foreign key or not, and
 * what deleting, restoring and merging do about it. A delete, the « Récemment supprimés » page, a merge and an import's
 * undo all read the same declaration, so a record is « used » in one sense everywhere.
 *
 * A merge may move only some of the rows naming the record that goes: those whose `$movesWhereColumn` holds one of
 * `$movesWhereValues` (a draft), while the others stay with it, sealed (an issued document, a movement).
 *
 * A column naming rows of several tables names this subject's only on the rows whose `$whereColumn` holds
 * `$whereValue`: a setting's `level_id` names a product where its `level` is `product`.
 *
 * A row naming the record kept, dated by `$transactingAt` at or after a merge and no longer one a merge would move,
 * is the record kept trading since (goods received, an expense in the books): the merge is then no longer undone.
 */
final readonly class RecordReference
{
    /**
     * @param list<string> $movesWhereValues
     * @param list<string> $twinColumns
     */
    private function __construct(
        public string $table,
        public string $column,
        public ?string $subject,
        public RecordUse $onDelete,
        public RecordMerge $onMerge,
        public RecordLink $link,
        public string $why,
        public ?string $movesWhereColumn = null,
        public array $movesWhereValues = [],
        public ?string $whereColumn = null,
        public ?string $whereValue = null,
        public array $twinColumns = [],
        public ?string $transactingAt = null,
        public ?string $parentTable = null,
        public ?string $parentKey = null,
    ) {
        foreach ([$table, $column, $subject ?? 'any', $movesWhereColumn ?? 'none', $whereColumn ?? 'none', $transactingAt ?? 'none', $parentTable ?? 'none', $parentKey ?? 'none', ...$twinColumns] as $name) {
            if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a name the records engine may write into SQL.', $name));
            }
        }
        if (null === $subject && RecordUse::Ignored !== $onDelete) {
            throw new \InvalidArgumentException(\sprintf('%s.%s: only a column that never names a subject may leave its subject unsaid.', $table, $column));
        }
    }

    /** A row naming the record makes it used: the delete is refused and says how many rows name it. */
    public static function uses(string $table, string $column, string $subject, RecordMerge $onMerge, string $why, RecordLink $link = RecordLink::Id): self
    {
        return new self($table, $column, $subject, RecordUse::Uses, $onMerge, $link, $why);
    }

    /** A row naming the record belongs to it: it goes with the record, is copied, and comes back with it. */
    public static function owned(string $table, string $column, string $subject, RecordMerge $onMerge, string $why, RecordLink $link = RecordLink::Id): self
    {
        return new self($table, $column, $subject, RecordUse::Owned, $onMerge, $link, $why);
    }

    /** The column is emptied when the record goes, and filled again when it is restored. */
    public static function cleared(string $table, string $column, string $subject, RecordMerge $onMerge, string $why, RecordLink $link = RecordLink::Id): self
    {
        return new self($table, $column, $subject, RecordUse::Cleared, $onMerge, $link, $why);
    }

    /**
     * The column never names the subject, or names it only as history (the audit's), which neither a delete nor a merge
     * changes; null for every subject at once.
     */
    public static function ignored(string $table, string $column, ?string $subject, string $why): self
    {
        return new self($table, $column, $subject, RecordUse::Ignored, RecordMerge::Ignored, RecordLink::Id, $why);
    }

    /**
     * Only the rows whose column holds one of the values move in a merge; the others stay with the merged-away record.
     *
     * @param list<string> $values
     */
    public function movingOnlyWhere(string $column, array $values): self
    {
        if (RecordMerge::Moves !== $this->onMerge || [] === $values) {
            throw new \InvalidArgumentException(\sprintf('%s.%s: only a reference that moves in a merge moves some rows, named by at least one value.', $this->table, $this->column));
        }

        return new self($this->table, $this->column, $this->subject, $this->onDelete, $this->onMerge, $this->link, $this->why, $column, $values, $this->whereColumn, $this->whereValue, $this->twinColumns, $this->transactingAt, $this->parentTable, $this->parentKey);
    }

    /**
     * The other columns that, with this one, make a row the record kept may already have a twin of: a merge drops the
     * merged-away record's twin instead of moving it.
     */
    public function droppingTwinsBy(string ...$columns): self
    {
        if (RecordMerge::MovesOrDrops !== $this->onMerge || [] === $columns) {
            throw new \InvalidArgumentException(\sprintf('%s.%s: only a reference that drops its twins in a merge says what makes one, by at least one column.', $this->table, $this->column));
        }

        return new self($this->table, $this->column, $this->subject, $this->onDelete, $this->onMerge, $this->link, $this->why, $this->movesWhereColumn, $this->movesWhereValues, $this->whereColumn, $this->whereValue, array_values($columns), $this->transactingAt, $this->parentTable, $this->parentKey);
    }

    /** The column names the subject only on the rows whose other column holds the value: the rest name other tables. */
    public function where(string $column, string $value): self
    {
        if (null === $this->subject) {
            throw new \InvalidArgumentException(\sprintf('%s.%s: a column said of every subject names none of them on some rows only.', $this->table, $this->column));
        }

        return new self($this->table, $this->column, $this->subject, $this->onDelete, $this->onMerge, $this->link, $this->why, $this->movesWhereColumn, $this->movesWhereValues, $column, $value, $this->twinColumns, $this->transactingAt, $this->parentTable, $this->parentKey);
    }

    /**
     * The row belongs to a document whose own row says what it is and when it was made (a line and its invoice): the
     * columns `movingOnlyWhere` and `transactingAt` then name are the document's, found by its key on this row.
     */
    public function through(string $parentTable, string $parentKey): self
    {
        if (null !== $this->movesWhereColumn || null !== $this->transactingAt) {
            throw new \InvalidArgumentException(\sprintf('%s.%s: say the document a row belongs to before its columns.', $this->table, $this->column));
        }

        return new self($this->table, $this->column, $this->subject, $this->onDelete, $this->onMerge, $this->link, $this->why, $this->movesWhereColumn, $this->movesWhereValues, $this->whereColumn, $this->whereValue, $this->twinColumns, $this->transactingAt, $parentTable, $parentKey);
    }

    /** Rows naming the record kept and dated by the column at or after a merge, outside what a merge moves, mean it traded since. */
    public function transactingAt(string $column): self
    {
        if (RecordUse::Ignored === $this->onDelete) {
            throw new \InvalidArgumentException(\sprintf('%s.%s: a column that names no record says no trade of one.', $this->table, $this->column));
        }

        return new self($this->table, $this->column, $this->subject, $this->onDelete, $this->onMerge, $this->link, $this->why, $this->movesWhereColumn, $this->movesWhereValues, $this->whereColumn, $this->whereValue, $this->twinColumns, $column, $this->parentTable, $this->parentKey);
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Infrastructure;

use App\Files\Infrastructure\Doctrine\UnnamedFiles;
use App\Records\Application\MergeConflict;
use App\Records\Application\RecordLink;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordStore;
use App\Records\Application\RecordSubject;
use App\Records\Application\RemovalConflict;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/**
 * The records engine in plain SQL, since it reads and moves rows of tables it knows only by the modules' declarations.
 * Every statement names the company itself: plain SQL escapes the company filter. Every name written into SQL was
 * checked when declared and is quoted here as well.
 *
 * A row is copied by the very statement that deletes it (`DELETE … RETURNING` into the copy), and goes back through
 * `jsonb_populate_record` with the columns it was copied with that the table still has, so a column added within the
 * day takes its default.
 */
final readonly class DbalRecordStore implements RecordStore
{
    /** The records a removal takes come back first; what belongs to them, which names them, after. */
    private const int RECORD_STEP = 0;
    private const int OWNED_STEP = 1;

    /** Copies the rows a statement named `gone` returns under the removal, at the step given. */
    private const string COPY = 'INSERT INTO record_removal_row (id, removal_id, company_id, table_name, step, snapshot) SELECT gen_random_uuid(), :removal, :company, :table, %d, to_jsonb(gone) FROM gone';

    public function __construct(private Connection $connection, private UnnamedFiles $unnamedFiles)
    {
    }

    public function lockedLabels(Uuid $companyId, RecordSubject $subject, array $ids): array
    {
        $label = implode(', ', array_map(fn (string $column): string => \sprintf('CAST(%s AS text)', $this->name($column)), $subject->labelColumns));
        // In the ids' order, so that two deletes of the same records never lock them crosswise. FOR UPDATE, not FOR NO KEY
        // UPDATE: a row written meanwhile with a foreign key to a record (a barcode, a photo) takes FOR KEY SHARE on it,
        // waits for this delete and then fails, so nothing belonging to the record escapes its copy.
        $rows = $this->connection->fetchAllAssociative(
            \sprintf("SELECT id::text AS id, concat_ws(' ', %s) AS label FROM %s WHERE company_id = :company AND id = ANY(CAST(:ids AS uuid[])) ORDER BY id FOR UPDATE", $label, $this->name($subject->table)),
            ['company' => $companyId->toRfc4122(), 'ids' => self::array($ids)],
        );
        $labels = [];
        foreach ($rows as $row) {
            $labels[self::text($row['id'])] = self::text($row['label']);
        }

        return $labels;
    }

    public function uses(Uuid $companyId, array $ids, array $references): array
    {
        $uses = [];
        foreach ($references as $reference) {
            [$naming, $parameters, $named] = $this->naming($reference, $companyId, $ids);
            $rows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT %1$s AS id, count(*)::int AS rows FROM %2$s WHERE %3$s GROUP BY 1', $named, $this->name($reference->table), $naming),
                $parameters,
            );
            foreach ($rows as $row) {
                $count = $row['rows'];
                $uses[self::text($row['id'])][] = ['table' => $reference->table, 'column' => $reference->column, 'count' => \is_int($count) ? $count : throw new \LogicException('A count reads as an integer.')];
            }
        }

        return $uses;
    }

    public function remove(Uuid $companyId, Uuid $removalId, RecordSubject $subject, array $ids, array $owned): void
    {
        // What belongs to the records first, a step after them, so that the restore puts it back once they stand again.
        foreach ($owned as $reference) {
            [$naming, $parameters] = $this->naming($reference, $companyId, $ids);
            $this->connection->executeStatement(
                \sprintf('WITH gone AS (DELETE FROM %s WHERE %s RETURNING *) '.self::COPY, $this->name($reference->table), $naming, self::OWNED_STEP),
                [...$parameters, 'removal' => $removalId->toRfc4122(), 'table' => $reference->table],
            );
        }
        $copied = $this->connection->executeStatement(
            \sprintf('WITH gone AS (DELETE FROM %s WHERE company_id = :company AND id = ANY(CAST(:ids AS uuid[])) RETURNING *) '.self::COPY, $this->name($subject->table), self::RECORD_STEP),
            ['company' => $companyId->toRfc4122(), 'ids' => self::array($ids), 'removal' => $removalId->toRfc4122(), 'table' => $subject->table],
        );
        if ($copied !== \count($ids)) {
            throw new \LogicException(\sprintf('%d rows of %s were asked for and %d copied: the delete stops rather than lose one.', \count($ids), $subject->table, $copied));
        }
    }

    public function restore(Uuid $companyId, Uuid $removalId): void
    {
        $parameters = ['company' => $companyId->toRfc4122(), 'removal' => $removalId->toRfc4122()];
        $table = null;
        // A savepoint, so that the transaction of the request survives a refusal and answers it.
        $this->connection->beginTransaction();
        try {
            $groups = $this->connection->fetchAllAssociative(
                'SELECT step, table_name FROM record_removal_row WHERE removal_id = :removal AND company_id = :company GROUP BY step, table_name ORDER BY step',
                $parameters,
            );
            foreach ($groups as $group) {
                $table = self::text($group['table_name']);
                $step = \is_int($group['step']) ? $group['step'] : throw new \LogicException('A copied row names its step.');
                $columns = array_map(fn (mixed $column): string => $this->name(self::text($column)), $this->connection->fetchFirstColumn(
                    'SELECT c.column_name FROM information_schema.columns c WHERE c.table_schema = current_schema() AND c.table_name = :table AND c.is_generated = \'NEVER\' AND EXISTS (SELECT 1 FROM record_removal_row d CROSS JOIN LATERAL jsonb_object_keys(d.snapshot) AS k(name) WHERE d.removal_id = :removal AND d.company_id = :company AND d.table_name = :table AND d.step = :step AND k.name = c.column_name) ORDER BY c.ordinal_position',
                    [...$parameters, 'table' => $table, 'step' => $step],
                ));
                $this->connection->executeStatement(
                    \sprintf('INSERT INTO %1$s (%2$s) SELECT %3$s FROM record_removal_row d CROSS JOIN LATERAL jsonb_populate_record(NULL::%1$s, d.snapshot) AS r WHERE d.removal_id = :removal AND d.company_id = :company AND d.table_name = :table AND d.step = :step', $this->name($table), implode(', ', $columns), implode(', ', array_map(static fn (string $column): string => 'r.'.$column, $columns))),
                    [...$parameters, 'table' => $table, 'step' => $step],
                );
            }
            $this->connection->executeStatement('DELETE FROM record_removal_row WHERE removal_id = :removal AND company_id = :company', $parameters);
            $this->connection->commit();
        } catch (UniqueConstraintViolationException|ForeignKeyConstraintViolationException|NotNullConstraintViolationException $inTheWay) {
            $this->connection->rollBack();

            throw new RemovalConflict($table ?? 'record_removal_row', $inTheWay);
        } catch (\Throwable $failure) {
            $this->connection->rollBack();

            throw $failure;
        }
    }

    public function descendsFrom(Uuid $companyId, Uuid $keptId, array $mergedIds, RecordReference $holding): bool
    {
        if (RecordLink::Id !== $holding->link || $holding->table !== $holding->subject) {
            throw new \LogicException(\sprintf('%s.%s does not hold a subject\'s records under one another.', $holding->table, $holding->column));
        }
        $table = $this->name($holding->table);
        $column = $this->name($holding->column);
        // Every record above the one kept, the depth bounding a loop that an earlier fault may have left.
        $found = $this->connection->fetchOne(
            \sprintf('WITH RECURSIVE above(id, depth) AS (SELECT h.%2$s, 1 FROM %1$s h WHERE h.company_id = :company AND h.id = :kept UNION ALL SELECT h.%2$s, above.depth + 1 FROM %1$s h JOIN above ON h.id = above.id WHERE h.company_id = :company AND above.depth < 1000) SELECT EXISTS (SELECT 1 FROM above WHERE id = ANY(CAST(:merged AS uuid[])))', $table, $column),
            ['company' => $companyId->toRfc4122(), 'kept' => $keptId->toRfc4122(), 'merged' => self::array($mergedIds)],
        );

        return \is_bool($found) ? $found : throw new \LogicException('An existence reads as a boolean.');
    }

    public function moveTo(Uuid $companyId, Uuid $mergeId, Uuid $keptId, Uuid $mergedId, array $moving): void
    {
        $table = null;
        // A savepoint, so that the transaction of the request survives a refusal and answers it.
        $this->connection->beginTransaction();
        try {
            foreach ($moving as $reference) {
                $table = $reference->table;
                $name = $this->name($reference->table);
                $column = $this->name($reference->column);
                $type = $this->columnType($reference->table, $reference->column);
                [$naming, $parameters] = $this->naming($reference, $companyId, [$mergedId]);
                if (null !== $reference->movesWhereColumn) {
                    $naming .= \sprintf(' AND %s::text = ANY(CAST(:moving AS text[]))', $this->name($reference->movesWhereColumn));
                    $parameters['moving'] = '{'.implode(',', array_map(static fn (string $value): string => '"'.addcslashes($value, '"\\').'"', $reference->movesWhereValues)).'}';
                }
                $kept = $this->value($reference, $companyId, $keptId);
                if ([] !== $reference->twinColumns) {
                    $twins = implode(' AND ', array_map(fn (string $twin): string => \sprintf('k.%1$s IS NOT DISTINCT FROM d.%1$s', $this->name($twin)), $reference->twinColumns));
                    $this->connection->executeStatement(
                        \sprintf('WITH gone AS (DELETE FROM %1$s AS d WHERE %2$s AND EXISTS (SELECT 1 FROM %1$s AS k WHERE k.company_id = d.company_id AND k.%3$s = CAST(:kept AS %4$s) AND %5$s) RETURNING d.*) ', $name, $naming, $column, $type, $twins).\sprintf(self::COPY, self::OWNED_STEP),
                        [...$parameters, 'kept' => $kept, 'removal' => $mergeId->toRfc4122(), 'table' => $reference->table],
                    );
                }
                $this->connection->executeStatement(
                    \sprintf('WITH moved AS (UPDATE %1$s SET %2$s = CAST(:kept AS %3$s)%4$s WHERE %5$s RETURNING id) INSERT INTO record_merge_move (id, removal_id, company_id, table_name, column_name, row_id, from_value, to_value) SELECT gen_random_uuid(), :merge, :company, :table, :column, id, :from, :kept FROM moved', $name, $column, $type, $this->versioned($reference->table, ''), $naming),
                    [...$parameters, 'kept' => $kept, 'merge' => $mergeId->toRfc4122(), 'table' => $reference->table, 'column' => $reference->column, 'from' => $this->value($reference, $companyId, $mergedId)],
                );
            }
            $this->connection->commit();
        } catch (UniqueConstraintViolationException $twice) {
            $this->connection->rollBack();
            throw new MergeConflict($table ?? 'record_merge_move', $twice);
        } catch (\Throwable $failure) {
            $this->connection->rollBack();
            throw $failure;
        }
    }

    public function moveBack(Uuid $companyId, Uuid $mergeId): void
    {
        $parameters = ['company' => $companyId->toRfc4122(), 'merge' => $mergeId->toRfc4122()];
        $table = null;
        $this->connection->beginTransaction();
        try {
            foreach ($this->connection->fetchAllAssociative('SELECT DISTINCT table_name, column_name FROM record_merge_move WHERE removal_id = :merge AND company_id = :company ORDER BY 1, 2', $parameters) as $moved) {
                $table = self::text($moved['table_name']);
                $column = self::text($moved['column_name']);
                // Only a row that still names the record kept: one someone filed elsewhere since stays as they set it.
                $this->connection->executeStatement(
                    \sprintf('UPDATE %1$s AS t SET %2$s = CAST(m.from_value AS %3$s)%4$s FROM record_merge_move m WHERE m.removal_id = :merge AND m.company_id = :company AND m.table_name = :table AND m.column_name = :column AND t.id = m.row_id AND t.company_id = :company AND t.%2$s::text = m.to_value', $this->name($table), $this->name($column), $this->columnType($table, $column), $this->versioned($table, 't.')),
                    [...$parameters, 'table' => $table, 'column' => $column],
                );
            }
            $this->connection->executeStatement('DELETE FROM record_merge_move WHERE removal_id = :merge AND company_id = :company', $parameters);
            $this->connection->commit();
        } catch (UniqueConstraintViolationException|ForeignKeyConstraintViolationException|NotNullConstraintViolationException $inTheWay) {
            $this->connection->rollBack();
            throw new RemovalConflict($table ?? 'record_merge_move', $inTheWay);
        } catch (\Throwable $failure) {
            $this->connection->rollBack();
            throw $failure;
        }
    }

    public function forget(Uuid $companyId, Uuid $removalId): array
    {
        $parameters = ['company' => $companyId->toRfc4122(), 'removal' => $removalId->toRfc4122()];
        $named = [];
        foreach ($this->connection->fetchFirstColumn('SELECT DISTINCT table_name FROM record_removal_row WHERE removal_id = :removal AND company_id = :company', $parameters) as $table) {
            foreach ($this->unnamedFiles->columnsOf(self::text($table)) as $column) {
                foreach ($this->connection->fetchFirstColumn(
                    'SELECT DISTINCT snapshot->>:column FROM record_removal_row WHERE removal_id = :removal AND company_id = :company AND table_name = :table AND snapshot->>:column IS NOT NULL',
                    [...$parameters, 'table' => $table, 'column' => $column],
                ) as $id) {
                    $named[] = self::text($id);
                }
            }
        }
        $this->connection->executeStatement('DELETE FROM record_removal_row WHERE removal_id = :removal AND company_id = :company', $parameters);
        $this->connection->executeStatement('DELETE FROM record_merge_move WHERE removal_id = :removal AND company_id = :company', $parameters);

        return $this->unnamedFiles->among($companyId, $named);
    }

    /**
     * Where a reference's column names some of the records, its scope said, and the record each row names as text. A
     * company path is matched whole, so the column's own index serves.
     *
     * @param list<Uuid> $ids
     *
     * @return array{string, array<string, string>, string} the condition, its parameters and the id each row names
     */
    private function naming(RecordReference $reference, Uuid $companyId, array $ids): array
    {
        $column = $this->name($reference->column);
        [$cast, $named] = match ($reference->link) {
            RecordLink::Id => ['uuid', $column.'::text'],
            RecordLink::CompanyPath => ['text', \sprintf("split_part(%s, '/', 2)", $column)],
            RecordLink::IdList => self::unread($reference),
        };
        $values = '{'.implode(',', array_map(fn (Uuid $id): string => $this->value($reference, $companyId, $id), $ids)).'}';
        $condition = \sprintf('company_id = :company AND %s = ANY(CAST(:ids AS %s[]))', $column, $cast);
        $parameters = ['company' => $companyId->toRfc4122(), 'ids' => $values];
        if (null !== $reference->whereColumn && null !== $reference->whereValue) {
            $condition .= \sprintf(' AND %s = :scope', $this->name($reference->whereColumn));
            $parameters['scope'] = $reference->whereValue;
        }

        return [$condition, $parameters, $named];
    }

    /** The column's own type, as the database writes it, which a value written into it is cast to. */
    private function columnType(string $table, string $column): string
    {
        $type = $this->connection->fetchOne(
            'SELECT format_type(a.atttypid, a.atttypmod) FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = :table AND a.attname = :column AND NOT a.attisdropped',
            ['table' => $table, 'column' => $column],
        );

        return \is_string($type) ? $type : throw new \LogicException(\sprintf('%s.%s has no type: it is gone.', $table, $column));
    }

    /**
     * A row a merge points elsewhere is another version of it, so an editor that had it open saves over it only knowingly;
     * a table that keeps no version is left as it is.
     */
    private function versioned(string $table, string $alias): string
    {
        $versioned = $this->connection->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table AND column_name = 'version')",
            ['table' => $table],
        );

        return true === $versioned ? \sprintf(', version = %sversion + 1', $alias) : '';
    }

    private function name(string $identifier): string
    {
        return $this->connection->quoteSingleIdentifier($identifier);
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \LogicException('A name, an id or a label reads as text.');
    }

    /** @param list<Uuid> $ids */
    private static function array(array $ids): string
    {
        return '{'.implode(',', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids)).'}';
    }

    /**
     * What a row of the reference holds when it names the record: its id, or the company's path to it as a setting's
     * level names its record. A delete, a merge and its undo all write and match through here.
     */
    private function value(RecordReference $reference, Uuid $companyId, Uuid $id): string
    {
        return match ($reference->link) {
            RecordLink::Id => $id->toRfc4122(),
            RecordLink::CompanyPath => $companyId->toRfc4122().'/'.$id->toRfc4122(),
            RecordLink::IdList => self::unread($reference),
        };
    }

    /** A list of ids is read element by element, which no reference asks yet. */
    private static function unread(RecordReference $reference): never
    {
        throw new \LogicException(\sprintf('%s.%s names its records as %s, which the records engine does not read yet.', $reference->table, $reference->column, $reference->link->value));
    }
}

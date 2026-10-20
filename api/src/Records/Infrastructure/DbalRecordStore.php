<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Infrastructure;

use App\Files\Infrastructure\Doctrine\UnnamedFiles;
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
            [$naming, $parameters] = $this->naming($reference, $companyId, $ids);
            $rows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT %1$s::text AS id, count(*)::int AS rows FROM %2$s WHERE %3$s GROUP BY %1$s', $this->name($reference->column), $this->name($reference->table), $naming),
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
        $copy = 'INSERT INTO record_removal_row (id, removal_id, company_id, table_name, step, snapshot) SELECT gen_random_uuid(), :removal, :company, :table, %d, to_jsonb(gone) FROM gone';
        // What belongs to the records first, a step after them, so that the restore puts it back once they stand again.
        foreach ($owned as $reference) {
            [$naming, $parameters] = $this->naming($reference, $companyId, $ids);
            $this->connection->executeStatement(
                \sprintf('WITH gone AS (DELETE FROM %s WHERE %s RETURNING *) '.$copy, $this->name($reference->table), $naming, self::OWNED_STEP),
                [...$parameters, 'removal' => $removalId->toRfc4122(), 'table' => $reference->table],
            );
        }
        $copied = $this->connection->executeStatement(
            \sprintf('WITH gone AS (DELETE FROM %s WHERE company_id = :company AND id = ANY(CAST(:ids AS uuid[])) RETURNING *) '.$copy, $this->name($subject->table), self::RECORD_STEP),
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

        return $this->unnamedFiles->among($companyId, $named);
    }

    /**
     * Where a reference's column names some of the records, its scope said: a text column holds an id as text.
     *
     * @param list<Uuid> $ids
     *
     * @return array{string, array<string, string>} the condition and its parameters
     */
    private function naming(RecordReference $reference, Uuid $companyId, array $ids): array
    {
        $cast = match ($reference->link) {
            RecordLink::Id => 'uuid',
            RecordLink::TextId => 'text',
            // A list of ids is read element by element, which no reference asks yet.
            RecordLink::IdList => throw new \LogicException(\sprintf('%s.%s names its records as %s, which the records engine does not read yet.', $reference->table, $reference->column, $reference->link->value)),
        };
        $condition = \sprintf('company_id = :company AND %s = ANY(CAST(:ids AS %s[]))', $this->name($reference->column), $cast);
        $parameters = ['company' => $companyId->toRfc4122(), 'ids' => self::array($ids)];
        if (null !== $reference->whereColumn && null !== $reference->whereValue) {
            $condition .= \sprintf(' AND %s = :scope', $this->name($reference->whereColumn));
            $parameters['scope'] = $reference->whereValue;
        }

        return [$condition, $parameters];
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
}

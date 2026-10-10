<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Infrastructure;

use App\Erasure\Application\ErasureConflict;
use App\Erasure\Application\ErasureReference;
use App\Erasure\Application\ErasureStore;
use App\Erasure\Domain\DataErasureRow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/**
 * The erasure in plain SQL, since it moves whole sets of rows of tables it knows only by name. Every statement names
 * the company itself: plain SQL escapes the company filter.
 *
 * A row is copied by the very statement that deletes it (`DELETE … RETURNING` into the copy), after the rows to take are
 * picked and locked: a line added to a draft meanwhile waits for the erasure, and is then refused for a draft that is
 * gone, rather than vanishing by cascade with no copy. A row goes back through `jsonb_populate_record` with the columns
 * it was copied with that the table still has, so a column added within the 24 hours takes its default.
 */
final readonly class DbalErasureStore implements ErasureStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function count(Uuid $companyId, array $steps, array $references): array
    {
        $plan = new ErasurePlan($steps, $references);

        return $this->counted($plan, $this->pick($companyId, $plan, false));
    }

    public function erase(Uuid $companyId, Uuid $erasureId, array $steps, array $references): array
    {
        $plan = new ErasurePlan($steps, $references);
        $picked = $this->pick($companyId, $plan, true);
        $company = $companyId->toRfc4122();
        $erasure = $erasureId->toRfc4122();

        foreach ($plan->links() as $link) {
            $named = $this->idsOn($plan, $picked, (string) $link->target);
            if ([] === $named) {
                continue;
            }
            $own = $this->idsOn($plan, $picked, $link->table);
            $where = \sprintf('company_id = :company AND %s = ANY(CAST(:named AS uuid[]))%s', $this->name($link->column), [] === $own ? '' : ' AND NOT (id = ANY(CAST(:own AS uuid[])))');
            $parameters = ['company' => $company, 'named' => self::array($named), 'own' => self::array($own)];
            $this->connection->executeStatement(
                \sprintf("INSERT INTO data_erasure_row (id, erasure_id, company_id, table_name, step, kind, snapshot) SELECT gen_random_uuid(), :erasure, :company, :table, -1, :kind, jsonb_build_object('id', id, 'column', CAST(:column AS text), 'value', %s) FROM %s WHERE %s", $this->name($link->column), $this->name($link->table), $where),
                [...$parameters, 'erasure' => $erasure, 'table' => $link->table, 'kind' => DataErasureRow::LINK, 'column' => $link->column],
            );
            $this->connection->executeStatement(\sprintf('UPDATE %s SET %s = NULL WHERE %s', $this->name($link->table), $this->name($link->column), $where), $parameters);
        }

        // The rows that belong to others first, so that no cascade reaches a row before its copy is made.
        for ($position = \count($plan->steps) - 1; $position >= 0; --$position) {
            $ids = $picked[$position];
            if ([] === $ids) {
                continue;
            }
            $table = $plan->steps[$position]->table;
            $copied = $this->connection->executeStatement(
                \sprintf('WITH gone AS (DELETE FROM %s WHERE company_id = :company AND id = ANY(CAST(:ids AS uuid[])) RETURNING *) INSERT INTO data_erasure_row (id, erasure_id, company_id, table_name, step, kind, snapshot) SELECT gen_random_uuid(), :erasure, :company, :table, :step, :kind, to_jsonb(gone) FROM gone', $this->name($table)),
                ['company' => $company, 'ids' => self::array($ids), 'erasure' => $erasure, 'table' => $table, 'step' => $position, 'kind' => DataErasureRow::ROW],
            );
            if ($copied !== \count($ids)) {
                throw new \LogicException(\sprintf('%d rows of %s were picked and %d copied: the erasure stops rather than lose one.', \count($ids), $table, $copied));
            }
        }

        return $this->counted($plan, $picked);
    }

    public function restore(Uuid $companyId, Uuid $erasureId, array $references): void
    {
        $parameters = ['company' => $companyId->toRfc4122(), 'erasure' => $erasureId->toRfc4122()];
        $table = null;
        // A savepoint, so that the transaction of the request survives a refusal and answers it.
        $this->connection->beginTransaction();
        try {
            $groups = $this->connection->fetchAllAssociative(
                'SELECT step, table_name FROM data_erasure_row WHERE erasure_id = :erasure AND company_id = :company AND kind = :kind GROUP BY step, table_name ORDER BY step',
                [...$parameters, 'kind' => DataErasureRow::ROW],
            );
            foreach ($groups as $group) {
                $table = \is_string($group['table_name']) ? $group['table_name'] : throw new \LogicException('A copied row names its table.');
                $step = \is_int($group['step']) ? $group['step'] : throw new \LogicException('A copied row names its step.');
                $columns = array_map(fn (mixed $column): string => $this->name(\is_string($column) ? $column : throw new \LogicException('A column has a name.')), $this->connection->fetchFirstColumn(
                    'SELECT c.column_name FROM information_schema.columns c WHERE c.table_schema = current_schema() AND c.table_name = :table AND c.is_generated = \'NEVER\' AND EXISTS (SELECT 1 FROM data_erasure_row d CROSS JOIN LATERAL jsonb_object_keys(d.snapshot) AS k(name) WHERE d.erasure_id = :erasure AND d.company_id = :company AND d.table_name = :table AND d.step = :step AND k.name = c.column_name) ORDER BY c.ordinal_position',
                    [...$parameters, 'table' => $table, 'step' => $step],
                ));
                $this->connection->executeStatement(
                    \sprintf('INSERT INTO %1$s (%2$s) SELECT %3$s FROM data_erasure_row d CROSS JOIN LATERAL jsonb_populate_record(NULL::%1$s, d.snapshot) AS r WHERE d.erasure_id = :erasure AND d.company_id = :company AND d.table_name = :table AND d.step = :step AND d.kind = :kind', $this->name($table), implode(', ', $columns), implode(', ', array_map(static fn (string $column): string => 'r.'.$column, $columns))),
                    [...$parameters, 'table' => $table, 'step' => $step, 'kind' => DataErasureRow::ROW],
                );
            }
            foreach ($references as $link) {
                if (ErasureReference::LINK !== $link->kind) {
                    continue;
                }
                $table = $link->table;
                // A link something has filled again since is left as it now is.
                $this->connection->executeStatement(
                    \sprintf("UPDATE %1\$s x SET %2\$s = CAST(d.snapshot->>'value' AS uuid) FROM data_erasure_row d WHERE d.erasure_id = :erasure AND d.company_id = :company AND d.kind = :kind AND d.table_name = :table AND d.snapshot->>'column' = :column AND x.id = CAST(d.snapshot->>'id' AS uuid) AND x.company_id = :company AND x.%2\$s IS NULL", $this->name($link->table), $this->name($link->column)),
                    [...$parameters, 'kind' => DataErasureRow::LINK, 'table' => $link->table, 'column' => $link->column],
                );
            }
            $this->connection->executeStatement('DELETE FROM data_erasure_row WHERE erasure_id = :erasure AND company_id = :company', $parameters);
            $this->connection->commit();
        } catch (UniqueConstraintViolationException|ForeignKeyConstraintViolationException|NotNullConstraintViolationException $inTheWay) {
            $this->connection->rollBack();

            throw new ErasureConflict($table ?? 'data_erasure_row', $inTheWay);
        } catch (\Throwable $failure) {
            $this->connection->rollBack();

            throw $failure;
        }
    }

    public function forget(Uuid $companyId, Uuid $erasureId, array $files): array
    {
        $parameters = ['company' => $companyId->toRfc4122(), 'erasure' => $erasureId->toRfc4122()];
        $named = [];
        foreach ($files as $file) {
            foreach ($this->connection->fetchFirstColumn(
                'SELECT DISTINCT d.snapshot->>:column FROM data_erasure_row d WHERE d.erasure_id = :erasure AND d.company_id = :company AND d.kind = :kind AND d.table_name = :table AND d.snapshot->>:column IS NOT NULL',
                [...$parameters, 'column' => $file->column, 'kind' => DataErasureRow::ROW, 'table' => $file->table],
            ) as $id) {
                $named[] = \is_string($id) ? $id : throw new \LogicException('A file id reads as text.');
            }
        }
        $this->connection->executeStatement('DELETE FROM data_erasure_row WHERE erasure_id = :erasure AND company_id = :company', $parameters);

        // What still names a file: every foreign key to it, and the columns declared as naming one without a key.
        $namers = array_map(static fn (array $key): array => [\is_string($key['table']) ? $key['table'] : throw new \LogicException('A table has a name.'), \is_string($key['column']) ? $key['column'] : throw new \LogicException('A column has a name.')], $this->connection->fetchAllAssociative(
            "SELECT t.relname::text AS \"table\", a.attname::text AS \"column\" FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_class r ON r.oid = c.confrelid JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1] WHERE c.contype = 'f' AND c.connamespace = current_schema()::regnamespace AND r.relname = 'file'",
        ));
        foreach ($files as $file) {
            $namers[] = [$file->table, $file->column];
        }
        $unnamed = [];
        foreach (array_unique($named) as $id) {
            foreach ($namers as [$table, $column]) {
                if (false !== $this->connection->fetchOne(\sprintf('SELECT 1 FROM %s WHERE company_id = :company AND %s = CAST(:id AS uuid) LIMIT 1', $this->name($table), $this->name($column)), ['company' => $parameters['company'], 'id' => $id])) {
                    continue 2;
                }
            }
            $unnamed[] = Uuid::fromString($id);
        }

        return $unnamed;
    }

    /**
     * The ids each step takes, picked in the plan's order and, while erasing, locked until the transaction ends.
     *
     * @return array<int, list<string>> by position in the plan
     */
    private function pick(Uuid $companyId, ErasurePlan $plan, bool $lock): array
    {
        $picked = [];
        foreach ($plan->steps as $position => $step) {
            $sql = \sprintf('SELECT id::text FROM %s WHERE company_id = :company AND (%s)', $this->name($step->table), $step->where);
            $parameters = ['company' => $companyId->toRfc4122()];
            if (null !== $step->parent && null !== $step->parentColumn) {
                $above = $picked[$plan->positionOf($step->parent)];
                if ([] === $above) {
                    $picked[$position] = [];
                    continue;
                }
                $sql .= \sprintf(' AND %s = ANY(CAST(:above AS uuid[]))', $this->name($step->parentColumn));
                $parameters['above'] = self::array($above);
            }
            foreach ($plan->keeping($step->table) as $index => $keep) {
                $erased = $this->idsOn($plan, $picked, $keep->table);
                // A row that names it keeps it only if that row stays.
                $sql .= \sprintf(' AND id NOT IN (SELECT %1$s FROM %2$s WHERE company_id = :company AND %1$s IS NOT NULL%3$s)', $this->name($keep->column), $this->name($keep->table), [] === $erased ? '' : " AND NOT (id = ANY(CAST(:kept$index AS uuid[])))");
                $parameters["kept$index"] = self::array($erased);
            }
            $picked[$position] = array_map(static fn (mixed $id): string => \is_string($id) ? $id : throw new \LogicException('An id reads as text.'), $this->connection->fetchFirstColumn($sql.($lock ? ' FOR UPDATE' : ''), $parameters));
        }

        return $picked;
    }

    /**
     * @param array<int, list<string>> $picked
     *
     * @return list<string>
     */
    private function idsOn(ErasurePlan $plan, array $picked, string $table): array
    {
        return array_merge(...array_map(static fn (int $position): array => $picked[$position] ?? [], $plan->stepsOn($table)));
    }

    /**
     * @param array<int, list<string>> $picked
     *
     * @return array<string, array<string, int>>
     */
    private function counted(ErasurePlan $plan, array $picked): array
    {
        $counts = [];
        foreach ($plan->steps as $position => $step) {
            if (null !== $step->counted) {
                $counts[$step->part][$step->counted] = ($counts[$step->part][$step->counted] ?? 0) + \count($picked[$position]);
            }
        }

        return $counts;
    }

    private function name(string $identifier): string
    {
        return $this->connection->quoteSingleIdentifier($identifier);
    }

    /** @param list<string> $ids */
    private static function array(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Infrastructure;

use App\Erasure\Application\DeclaresErasure;
use Doctrine\DBAL\Connection;

/**
 * What the database has that the erasure was not told about: a table no longer there that a part still erases, and a
 * column reaching a table a part erases that no step copies and no reference classifies. A column reaches a table by
 * its foreign key, or, with none, by its name (`model_invoice_id`, `spot_id` for `venue_spot`); a column that may name
 * rows of any table (`entity_id`, `source_id`) must be classified once, whatever it names.
 */
final class ErasureCoverage
{
    /** Columns naming a row of whichever table another column of theirs says. */
    private const array ANY_TABLE = ['entity_id', 'source_id', 'subject_id', 'record_id'];

    /**
     * @param iterable<DeclaresErasure> $declarations
     *
     * @return list<string>
     */
    public static function unaccounted(Connection $connection, iterable $declarations): array
    {
        $steps = [];
        $references = [];
        foreach ($declarations as $declaration) {
            $steps = [...$steps, ...$declaration->steps()];
            $references = [...$references, ...$declaration->references()];
        }
        /** @var array<string, string> $erased the part erasing each table */
        $erased = [];
        foreach ($steps as $step) {
            $erased[$step->table] ??= $step->part;
        }

        $out = [];
        $tables = self::texts($connection->fetchFirstColumn('SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()'));
        foreach ($steps as $step) {
            if (!\in_array($step->table, $tables, true)) {
                $out[] = \sprintf('%s no longer exists, and the part %s still erases it', $step->table, $step->part);
            }
        }

        $covered = [];
        foreach ($steps as $step) {
            if (null !== $step->parent && null !== $step->parentColumn) {
                $covered[] = "$step->table.$step->parentColumn>{$step->parent->table}";
                if (\in_array($step->parentColumn, self::ANY_TABLE, true)) {
                    $covered[] = "$step->table.$step->parentColumn>*";
                }
            }
        }
        foreach ($references as $reference) {
            $covered[] = "$reference->table.$reference->column>".($reference->target ?? '*');
        }
        $isCovered = static fn (string $table, string $column, string $target): bool => \in_array("$table.$column>$target", $covered, true) || \in_array("$table.$column>*", $covered, true);
        $unclassified = static fn (string $table, string $column, string $target) => \sprintf('%s.%s points at %s, which the part %s erases, and is neither copied nor classified', $table, $column, $target, $erased[$target]);

        $keyed = [];
        foreach ($connection->fetchAllAssociative(
            "SELECT t.relname::text AS \"table\", a.attname::text AS \"column\", r.relname::text AS target FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_class r ON r.oid = c.confrelid JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1] WHERE c.contype = 'f' AND c.connamespace = current_schema()::regnamespace ORDER BY 1, 2",
        ) as $key) {
            [$table, $column, $target] = [self::text($key['table']), self::text($key['column']), self::text($key['target'])];
            $keyed[] = "$table.$column";
            if (isset($erased[$target]) && !$isCovered($table, $column, $target)) {
                $out[] = $unclassified($table, $column, $target);
            }
        }

        foreach ($connection->fetchAllAssociative(
            "SELECT table_name::text AS \"table\", column_name::text AS \"column\" FROM information_schema.columns WHERE table_schema = current_schema() AND data_type = 'uuid' AND column_name NOT IN ('id', 'company_id') ORDER BY 1, 2",
        ) as $uuid) {
            [$table, $column] = [self::text($uuid['table']), self::text($uuid['column'])];
            if (\in_array("$table.$column", $keyed, true)) {
                continue;
            }
            if (\in_array($column, self::ANY_TABLE, true)) {
                if ([] !== $erased && !\in_array("$table.$column>*", $covered, true)) {
                    $out[] = \sprintf('%s.%s may name rows of any table, the erased ones included, and is not classified', $table, $column);
                }
                continue;
            }
            foreach (array_keys($erased) as $target) {
                if (self::namedAfter($column, $target) && !$isCovered($table, $column, $target)) {
                    $out[] = $unclassified($table, $column, $target);
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * `invoice_id` and `model_invoice_id` for `invoice`, and `spot_id` for `venue_spot`: what a column naming that table's
     * rows is called. The table's last word alone counts only as the whole name, or every `…_line_id` would name lines.
     */
    private static function namedAfter(string $column, string $table): bool
    {
        $last = substr((string) strrchr('_'.$table, '_'), 1);

        return $column === $table.'_id' || str_ends_with($column, '_'.$table.'_id') || $column === $last.'_id';
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private static function texts(array $values): array
    {
        return array_map(self::text(...), $values);
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \LogicException('A catalogue name reads as text.');
    }
}

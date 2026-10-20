<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Infrastructure;

use App\Records\Application\RecordCatalogue;
use App\Records\Application\RecordReference;
use App\Shared\Infrastructure\Doctrine\SchemaLinks;
use Doctrine\DBAL\Connection;

/**
 * What the database has that the records engine was not told about: a column naming a subject's records that no module
 * declared, by its foreign key, by its name when it has none (`purchase_order.vendor_id`), as a list of ids
 * (`default_tax_component_ids`), or as a column naming rows of any table (`entity_id`, a setting's `level_id`); and a
 * subject or reference whose table or column is gone. An undeclared column would let a delete pass over a record that
 * column still names, or a merge leave it naming the record that went.
 */
final class RecordReferenceCoverage
{
    /** Columns naming a row of whichever table another column of theirs says, whatever their type. */
    private const array ANY_TABLE = ['entity_id', 'source_id', 'subject_id', 'record_id', 'level_id'];

    /** @return list<string> */
    public static function unaccounted(Connection $connection, RecordCatalogue $catalogue): array
    {
        $schema = new SchemaLinks($connection);
        $subjects = array_keys($catalogue->subjects());
        $declared = array_map(static fn (RecordReference $reference): string => "$reference->table.$reference->column>".($reference->subject ?? '*'), $catalogue->references());
        $isDeclared = static fn (string $table, string $column, string $subject): bool => \in_array("$table.$column>$subject", $declared, true) || \in_array("$table.$column>*", $declared, true);
        $undeclared = static fn (string $table, string $column, string $subject): string => \sprintf('%s.%s names %s records and is not declared', $table, $column, $subject);

        $out = [];
        $tables = $schema->tables();
        foreach ($subjects as $subject) {
            if (!\in_array($subject, $tables, true)) {
                $out[] = \sprintf('the subject %s has no table', $subject);
            }
        }
        $columns = $schema->columns();
        foreach ($catalogue->references() as $reference) {
            if (!\in_array("$reference->table.$reference->column", $columns, true)) {
                $out[] = \sprintf('%s.%s is declared and no longer exists', $reference->table, $reference->column);
            }
        }

        $keyed = [];
        foreach ($schema->foreignKeys() as ['table' => $table, 'column' => $column, 'target' => $target]) {
            $keyed[] = "$table.$column";
            if (\in_array($target, $subjects, true) && !$isDeclared($table, $column, $target)) {
                $out[] = $undeclared($table, $column, $target);
            }
        }

        $anyTable = [];
        foreach ([...$schema->columnsOfType('uuid'), ...$schema->columnsOfType('character varying')] as ['table' => $table, 'column' => $column]) {
            if (\in_array($column, self::ANY_TABLE, true)) {
                $anyTable[] = [$table, $column];
            }
        }
        foreach ($anyTable as [$table, $column]) {
            foreach ($subjects as $subject) {
                if (!$isDeclared($table, $column, $subject)) {
                    $out[] = \sprintf('%s.%s may name %s records and is not declared', $table, $column, $subject);
                }
            }
        }

        foreach ($schema->columnsOfType('uuid') as ['table' => $table, 'column' => $column]) {
            if (\in_array("$table.$column", $keyed, true) || \in_array($column, self::ANY_TABLE, true)) {
                continue;
            }
            foreach ($subjects as $subject) {
                if (SchemaLinks::namedAfter($column, $subject) && !$isDeclared($table, $column, $subject)) {
                    $out[] = $undeclared($table, $column, $subject);
                }
            }
        }

        foreach ($schema->columnsOfType('jsonb') as ['table' => $table, 'column' => $column]) {
            if (!str_ends_with($column, '_ids')) {
                continue;
            }
            foreach ($subjects as $subject) {
                if (SchemaLinks::namedAfter(substr($column, 0, -1), $subject) && !$isDeclared($table, $column, $subject)) {
                    $out[] = $undeclared($table, $column, $subject);
                }
            }
        }

        return array_values(array_unique($out));
    }
}

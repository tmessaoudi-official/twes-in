<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Infrastructure;

use App\Records\Application\MergedAway;
use App\Records\Application\RecordCatalogue;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordUse;
use App\Shared\Infrastructure\Doctrine\SchemaLinks;
use Doctrine\DBAL\Connection;

/**
 * What the database has that the records engine was not told about: a column naming a subject's records that no module
 * declared, by its foreign key, by its name when it has none (`purchase_order.vendor_id`), as a list of ids
 * (`default_tax_component_ids`), or as a column naming rows of any table (`entity_id`, a setting's `level_id`); and a
 * subject or reference whose table or column is gone; and a foreign key to a table whose rows go with a subject. An
 * undeclared column would let a delete pass over a record that column still names, or a merge leave it naming the
 * record that went.
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
        foreach ($catalogue->subjects() as $subject) {
            if (MergedAway::Aliased !== $subject->mergedAway) {
                continue;
            }
            foreach (['merged_into_id', 'is_active'] as $column) {
                if (!\in_array("$subject->table.$column", $columns, true)) {
                    $out[] = \sprintf('the subject %1$s is merged as an alias and %1$s has no %2$s', $subject->table, $column);
                }
            }
        }
        foreach ($catalogue->references() as $reference) {
            if (!\in_array("$reference->table.$reference->column", $columns, true)) {
                $out[] = \sprintf('%s.%s is declared and no longer exists', $reference->table, $reference->column);
            }
        }

        // An owned table's rows are copied and deleted by the subject's ids, so a row naming one of them would be emptied,
        // cascaded away uncopied, or refuse the delete, unless declared as naming the record through that row (a line's
        // tax); a row owned that way is itself named by nothing.
        $ownedBy = [];
        $below = [];
        foreach ($catalogue->references() as $reference) {
            if (RecordUse::Owned === $reference->onDelete && null !== $reference->subject) {
                $ownedBy[$reference->table] = $reference->subject;
            }
            if (null !== $reference->belowTable) {
                $below["$reference->table.$reference->column"] = $reference->belowTable;
            }
        }
        $ownedDirectly = [];
        foreach ($catalogue->references() as $reference) {
            if (RecordUse::Owned === $reference->onDelete && null !== $reference->subject && null === $reference->belowTable) {
                $ownedDirectly[$reference->table] = true;
            }
        }
        foreach ($catalogue->references() as $reference) {
            if (null !== $reference->belowTable && !isset($ownedDirectly[$reference->belowTable])) {
                $out[] = \sprintf('%s.%s names the record through %s, whose rows are not owned by it directly', $reference->table, $reference->column, $reference->belowTable);
            }
        }

        $keyed = [];
        foreach ($schema->foreignKeys() as ['table' => $table, 'column' => $column, 'target' => $target]) {
            $keyed[] = "$table.$column";
            if (\in_array($target, $subjects, true) && !$isDeclared($table, $column, $target)) {
                $out[] = $undeclared($table, $column, $target);
            }
            if (isset($ownedBy[$target]) && !(isset($ownedDirectly[$target]) && ($below["$table.$column"] ?? null) === $target)) {
                $out[] = \sprintf('%s.%s names %s rows, which go with a %s, and nothing can declare it', $table, $column, $target, $ownedBy[$target]);
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
            // By the owned table's whole name: its last word alone (`location_id` for product_home_location) names others.
            foreach (array_keys($ownedDirectly) as $owned) {
                if (($column === $owned.'_id' || str_ends_with($column, '_'.$owned.'_id')) && ($below["$table.$column"] ?? null) !== $owned) {
                    $out[] = \sprintf('%s.%s names %s rows, which go with a %s, and is not declared as naming it through them', $table, $column, $owned, $ownedBy[$owned]);
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

        // A merge drops the merged-away record's twin of a row the kept record has; what makes a twin is what the unique
        // index refuses twice, the company's own column aside.
        foreach ($catalogue->references() as $reference) {
            if ([] === $reference->twinColumns) {
                continue;
            }
            $twin = [$reference->column, ...$reference->twinColumns, ...(null === $reference->whereColumn ? [] : [$reference->whereColumn])];
            sort($twin);
            $held = false;
            foreach (self::uniqueIndexes($connection, $reference->table) as $columns) {
                $columns = array_values(array_diff($columns, ['company_id']));
                sort($columns);
                $held = $held || $columns === array_values(array_unique($twin));
            }
            if (!$held) {
                $out[] = \sprintf('%s.%s drops its twins by %s, which no unique index of %s holds with it', $reference->table, $reference->column, implode(', ', $reference->twinColumns), $reference->table);
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<list<string>> the columns of each unique index of the table, its primary key left out */
    private static function uniqueIndexes(Connection $connection, string $table): array
    {
        $indexes = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT i.indexrelid::text AS index, a.attname AS name FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid JOIN pg_namespace n ON n.oid = t.relnamespace JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(i.indkey) WHERE n.nspname = current_schema() AND t.relname = :table AND i.indisunique AND NOT i.indisprimary',
            ['table' => $table],
        ) as $row) {
            if (!\is_string($row['index']) || !\is_string($row['name'])) {
                throw new \LogicException('An index and its columns read as names.');
            }
            $indexes[$row['index']][] = $row['name'];
        }

        return array_values($indexes);
    }
}

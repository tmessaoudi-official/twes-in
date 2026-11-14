<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\Inventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Shared\Infrastructure\Doctrine\SchemaLinks;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * What the database has that the fiscal inventory was not told about: a table with an issued state (a status, a number,
 * an issue stamp, a hash chain, a stored PDF), or a column reaching a table the inventory counts, that is neither counted
 * nor said not to be fiscal; a stored PDF column no member counts; and a table or column a member still names that is gone.
 */
final class FiscalInventoryCoverage
{
    /** Columns whose presence says a table's rows can be issued, numbered or sealed. */
    private const array ISSUED_STATE = ['status', 'number', 'issued_at', 'pdf_file_id'];

    /** Columns naming a row of whichever table another column of theirs says. */
    private const array ANY_TABLE = ['entity_id', 'source_id', 'subject_id', 'record_id', 'document_id'];

    /**
     * @param iterable<DeclaresFiscalRecords> $declarations
     *
     * @return list<string>
     */
    public static function unaccounted(Connection $connection, iterable $declarations): array
    {
        $schema = new SchemaLinks($connection);
        $tables = $schema->tables();
        $columns = $schema->columns();
        /** @var array<string, string> $counted the member counting each table, its parts included */
        $counted = [];
        /** @var array<string, string> $notFiscal */
        $notFiscal = [];
        /** @var list<string> $pdfs every `table.column` a member counts stored documents in */
        $pdfs = [];
        $out = [];
        foreach ($declarations as $declaration) {
            foreach ($declaration->fiscalRecords() as $records) {
                foreach ([$records->table, ...$records->parts] as $table) {
                    $counted[$table] ??= $records->code;
                    if (!\in_array($table, $tables, true)) {
                        $out[] = \sprintf('%s no longer exists, and %s still counts it', $table, $records->code);
                    }
                }
                foreach ($records->columns() as $column) {
                    if (\in_array($records->table, $tables, true) && !\in_array("$records->table.$column", $columns, true)) {
                        $out[] = \sprintf('%s.%s no longer exists, and %s still reads it', $records->table, $column, $records->code);
                    }
                }
                if (null !== $records->pdfColumn) {
                    $pdfs[] = "$records->table.$records->pdfColumn";
                }
            }
            foreach ($declaration->notFiscal() as $table => $why) {
                $notFiscal[$table] = $why;
                if (!\in_array($table, $tables, true)) {
                    $out[] = \sprintf('%s no longer exists, and is still said not to be fiscal', $table);
                }
            }
        }
        $accounted = static fn (string $table): bool => isset($counted[$table]) || isset($notFiscal[$table]);
        $unaccounted = static fn (string $table): string => \sprintf('and %s is neither counted nor said not to be fiscal', $table);

        /** @var array<string, list<string>> $issued */
        $issued = [];
        foreach ($connection->fetchAllAssociative(
            "SELECT table_name::text AS \"table\", column_name::text AS \"column\" FROM information_schema.columns WHERE table_schema = current_schema() AND (column_name IN (?) OR column_name LIKE 'chain\\_%' OR column_name LIKE '%\\_pdf\\_file\\_id') ORDER BY table_name, ordinal_position",
            [self::ISSUED_STATE],
            [ArrayParameterType::STRING],
        ) as $row) {
            $table = Rows::text($row['table']);
            $column = Rows::text($row['column']);
            $issued[$table][] = $column;
            if (str_ends_with($column, 'pdf_file_id') && !\in_array("$table.$column", $pdfs, true)) {
                $out[] = \sprintf('%s.%s holds a stored document and no member counts it', $table, $column);
            }
        }
        foreach ($issued as $table => $found) {
            if (\in_array($table, $tables, true) && !$accounted($table)) {
                $out[] = \sprintf('%s has an issued state (%s) and is neither counted nor said not to be fiscal', $table, implode(', ', $found));
            }
        }

        $keyed = [];
        foreach ($schema->foreignKeys() as ['table' => $table, 'column' => $column, 'target' => $target]) {
            $keyed[] = "$table.$column";
            if (isset($counted[$target]) && !$accounted($table)) {
                $out[] = \sprintf('%s.%s points at %s, which the inventory counts, %s', $table, $column, $target, $unaccounted($table));
            }
        }
        foreach ($schema->columnsOfType('uuid') as ['table' => $table, 'column' => $column]) {
            if (\in_array("$table.$column", $keyed, true) || $accounted($table)) {
                continue;
            }
            if (\in_array($column, self::ANY_TABLE, true)) {
                $out[] = \sprintf('%s.%s may name rows of any table, the counted ones included, %s', $table, $column, $unaccounted($table));
                continue;
            }
            foreach (array_keys($counted) as $target) {
                if (SchemaLinks::namedAfter($column, $target)) {
                    $out[] = \sprintf('%s.%s points at %s, which the inventory counts, %s', $table, $column, $target, $unaccounted($table));
                }
            }
        }

        return array_values(array_unique($out));
    }
}

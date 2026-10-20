<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;

/**
 * What the database says about which columns name which tables' rows, read by the coverage checks of the engines that
 * remove rows (the erasure, the delete and merge of records): a column names a table by its foreign key or, with none,
 * by its name.
 */
final readonly class SchemaLinks
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> */
    public function tables(): array
    {
        return self::texts($this->connection->fetchFirstColumn('SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()'));
    }

    /** @return list<string> every column, as `table.column` */
    public function columns(): array
    {
        return self::texts($this->connection->fetchFirstColumn("SELECT table_name || '.' || column_name FROM information_schema.columns WHERE table_schema = current_schema()"));
    }

    /** @return list<array{table: string, column: string, target: string}> */
    public function foreignKeys(): array
    {
        return array_map(static fn (array $key): array => ['table' => self::text($key['table']), 'column' => self::text($key['column']), 'target' => self::text($key['target'])], $this->connection->fetchAllAssociative(
            "SELECT t.relname::text AS \"table\", a.attname::text AS \"column\", r.relname::text AS target FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_class r ON r.oid = c.confrelid JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1] WHERE c.contype = 'f' AND c.connamespace = current_schema()::regnamespace ORDER BY 1, 2",
        ));
    }

    /**
     * The columns of a type, its row's own id and company left out.
     *
     * @return list<array{table: string, column: string}>
     */
    public function columnsOfType(string $type): array
    {
        return array_map(static fn (array $column): array => ['table' => self::text($column['table']), 'column' => self::text($column['column'])], $this->connection->fetchAllAssociative(
            "SELECT table_name::text AS \"table\", column_name::text AS \"column\" FROM information_schema.columns WHERE table_schema = current_schema() AND data_type = ? AND column_name NOT IN ('id', 'company_id') ORDER BY 1, 2",
            [$type],
        ));
    }

    /**
     * `invoice_id` and `model_invoice_id` for `invoice`, and `spot_id` for `venue_spot`: what a column naming that table's
     * rows is called. The table's last word alone counts only as the whole name, or every `…_line_id` would name lines.
     */
    public static function namedAfter(string $column, string $table): bool
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

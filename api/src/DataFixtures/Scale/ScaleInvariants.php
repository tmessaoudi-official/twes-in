<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures\Scale;

use Doctrine\DBAL\Connection;

/**
 * Invariants a foreign key cannot state, read from the database's own catalogue so a table added tomorrow is covered
 * without anyone remembering it. Written for the large-data run and meant to be lifted by the data health check
 * (docs/SPEC.md row 182), which asks the same questions of any database.
 */
final class ScaleInvariants
{
    /**
     * Every foreign key whose two tables both carry a `company_id`, where some row's company differs from the company
     * of the row it points at. Such a row satisfies the constraint and is still wrong: the company filter hides it
     * from its own company or shows it to another.
     *
     * @return list<array{table: string, column: string, target: string, rows: int}>
     */
    public static function crossCompanyReferences(Connection $connection): array
    {
        $found = [];
        foreach (self::companyScopedForeignKeys($connection) as $key) {
            $rows = Rows::int($connection->fetchOne(\sprintf(
                'SELECT COUNT(*) FROM %1$s c JOIN %2$s p ON p.id = c.%3$s WHERE c.company_id <> p.company_id',
                self::quote($key['table']),
                self::quote($key['target']),
                self::quote($key['column']),
            )));
            if ($rows > 0) {
                $found[] = ['table' => $key['table'], 'column' => $key['column'], 'target' => $key['target'], 'rows' => $rows];
            }
        }

        return $found;
    }

    /**
     * Single-column foreign keys between two tables that each have a `company_id` column and a `uuid` primary key
     * named `id`; `company` itself is excluded, where the column is the identity and not a reference.
     *
     * @return list<array{table: string, column: string, target: string}>
     */
    public static function companyScopedForeignKeys(Connection $connection): array
    {
        /** @var list<array{table: string, column: string, target: string}> $keys */
        $keys = $connection->fetchAllAssociative(<<<'SQL'
            SELECT t.relname::text AS "table", a.attname::text AS "column", r.relname::text AS target
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_class r ON r.oid = c.confrelid
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
            WHERE c.contype = 'f' AND cardinality(c.conkey) = 1
              AND c.connamespace = 'public'::regnamespace
              AND a.attname <> 'company_id'
              AND EXISTS (SELECT 1 FROM pg_attribute x WHERE x.attrelid = c.conrelid AND x.attname = 'company_id' AND NOT x.attisdropped)
              AND EXISTS (SELECT 1 FROM pg_attribute y WHERE y.attrelid = c.confrelid AND y.attname = 'company_id' AND NOT y.attisdropped)
            ORDER BY 1, 2
            SQL);

        return $keys;
    }

    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}

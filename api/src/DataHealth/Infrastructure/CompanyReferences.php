<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataHealth\Infrastructure;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Rows that point into another company, read from the database's own catalogue so a table added tomorrow is covered
 * without anyone remembering it. Such a row satisfies its foreign key and is still wrong: the company filter hides it
 * from its own company or shows it to another. The large-data run asks it too, of every company it grows.
 */
final readonly class CompanyReferences implements DeclaresDataChecks
{
    public const string CHECK = 'references.company';

    public function __construct(private Connection $connection)
    {
    }

    public function dataChecks(): array
    {
        return [self::CHECK];
    }

    public function findings(string $check): iterable
    {
        foreach ($this->crossCompanyReferences() as $hit) {
            yield new DataFinding(self::CHECK, Uuid::fromString($hit['company']), \sprintf('%s.%s names %d %s of another company', $hit['table'], $hit['column'], $hit['rows'], $hit['target']));
        }
    }

    /**
     * Every foreign key whose two tables both carry a `company_id`, where some row's company differs from the company
     * of the row it points at, per company of the pointing rows.
     *
     * @return list<array{table: string, column: string, target: string, company: string, rows: int}>
     */
    public function crossCompanyReferences(): array
    {
        $found = [];
        foreach ($this->companyScopedForeignKeys() as $key) {
            foreach ($this->connection->fetchAllAssociative(\sprintf(
                'SELECT c.company_id::text AS company, COUNT(*) AS rows FROM %1$s c JOIN %2$s p ON p.id = c.%3$s WHERE c.company_id <> p.company_id GROUP BY c.company_id ORDER BY 1',
                self::quote($key['table']),
                self::quote($key['target']),
                self::quote($key['column']),
            )) as $row) {
                if (!\is_string($row['company']) || !is_numeric($row['rows'])) {
                    throw new \UnexpectedValueException('A count of references read something other than a company and a number.');
                }
                $found[] = [...$key, 'company' => $row['company'], 'rows' => (int) $row['rows']];
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
    private function companyScopedForeignKeys(): array
    {
        /** @var list<array{table: string, column: string, target: string}> $keys */
        $keys = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT t.relname::text AS "table", a.attname::text AS "column", r.relname::text AS target
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_class r ON r.oid = c.confrelid
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
            WHERE c.contype = 'f' AND cardinality(c.conkey) = 1
              AND c.connamespace = current_schema()::regnamespace
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

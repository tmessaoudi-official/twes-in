<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataHealth\Infrastructure;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Shared\Infrastructure\Doctrine\SchemaLinks;
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
                $found[] = [...$key, 'company' => Rows::text($row['company']), 'rows' => Rows::int($row['rows'])];
            }
        }

        return $found;
    }

    /**
     * The foreign keys between two tables that each have a `company_id` column, as the schema's one reading of its keys
     * gives them; `company` itself has none, where the column is the identity and not a reference.
     *
     * @return list<array{table: string, column: string, target: string}>
     */
    private function companyScopedForeignKeys(): array
    {
        $schema = new SchemaLinks($this->connection);
        $scoped = [];
        foreach ($schema->columns() as $column) {
            if (str_ends_with($column, '.company_id')) {
                $scoped[substr($column, 0, -\strlen('.company_id'))] = true;
            }
        }

        return array_values(array_filter(
            $schema->foreignKeys(),
            static fn (array $key): bool => 'company_id' !== $key['column'] && isset($scoped[$key['table']], $scoped[$key['target']]),
        ));
    }

    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}

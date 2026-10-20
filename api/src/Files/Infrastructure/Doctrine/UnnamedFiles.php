<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\SchemaLinks;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Which of a company's files nothing names any more, once the rows that named them are gone for good: what the erasure
 * and the delete of records let go when their undo ends. A file is still named by every foreign key to it, by the
 * columns the caller says name one without a key, and by a row copied for an undo still pending, of either engine,
 * whose table names it: that row may yet come back.
 */
final readonly class UnnamedFiles
{
    /** The undo copies: each row as it was, under the table it came from. */
    private const array COPIES = ['data_erasure_row', 'record_removal_row'];

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<string>                               $ids         file ids, as text
     * @param list<array{table: string, column: string}> $alsoNamedBy columns naming a file without a foreign key
     *
     * @return list<Uuid>
     */
    public function among(Uuid $companyId, array $ids, array $alsoNamedBy = []): array
    {
        $namers = [...$this->keyedNamers(), ...$alsoNamedBy];
        $company = $companyId->toRfc4122();
        $unnamed = [];
        foreach (array_unique($ids) as $id) {
            foreach ($namers as ['table' => $table, 'column' => $column]) {
                if (false !== $this->connection->fetchOne(\sprintf('SELECT 1 FROM %s WHERE company_id = :company AND %s = CAST(:id AS uuid) LIMIT 1', $this->name($table), $this->name($column)), ['company' => $company, 'id' => $id])) {
                    continue 2;
                }
                foreach (self::COPIES as $copies) {
                    if (false !== $this->connection->fetchOne(\sprintf('SELECT 1 FROM %s WHERE company_id = :company AND table_name = :table AND snapshot->>:column = :id LIMIT 1', $this->name($copies)), ['company' => $company, 'table' => $table, 'column' => $column, 'id' => $id])) {
                        continue 3;
                    }
                }
            }
            $unnamed[] = Uuid::fromString($id);
        }

        return $unnamed;
    }

    /**
     * The columns of a table that name a file by a foreign key: those whose values a copied row of that table holds.
     *
     * @return list<string>
     */
    public function columnsOf(string $table): array
    {
        return array_values(array_map(static fn (array $namer): string => $namer['column'], array_filter($this->keyedNamers(), static fn (array $namer): bool => $table === $namer['table'])));
    }

    /** @return list<array{table: string, column: string}> */
    private function keyedNamers(): array
    {
        return array_values(array_map(
            static fn (array $key): array => ['table' => $key['table'], 'column' => $key['column']],
            array_filter((new SchemaLinks($this->connection))->foreignKeys(), static fn (array $key): bool => 'file' === $key['target']),
        ));
    }

    private function name(string $identifier): string
    {
        return $this->connection->quoteSingleIdentifier($identifier);
    }
}

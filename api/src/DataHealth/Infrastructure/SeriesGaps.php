<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataHealth\Infrastructure;

use App\DataHealth\Application\DataFinding;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * A numbered document type's numbers, read without a gap in each series: every context numbering through
 * `AllocateNumber` hands its own documents here, so the rule is written once and each context names only its table.
 *
 * Each series' numbers, in the order they were issued, follow one another, or start again at one where a month begins:
 * read from the documents, not from the series' reset as it stands today, which may have been revised since (yearly to
 * monthly) and would then see a gap at every month the earlier numbers ran through. The first number may be any, where
 * the company continued a sequence begun elsewhere. The sequence is the number's last digits, which is where every
 * format ends it; a series whose format prints something after it is not checked here rather than reported every night
 * for a gap it does not have.
 */
final readonly class SeriesGaps
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param string $documents a query of the context's own numbered documents, naming `company_id`, `establishment_id`,
     *                          `document_type` (the series' type), `number`, `issue_date` and `id`
     *
     * @return iterable<DataFinding>
     */
    public function findings(string $check, string $documents): iterable
    {
        $rows = $this->connection->iterateAssociative(<<<SQL
            WITH documents AS ({$documents}),
            sequenced AS (
                SELECT d.company_id, d.establishment_id, d.document_type, d.number, d.issue_date, d.id,
                       substring(d.number FROM '([0-9]+)$')::bigint AS sequence,
                       regexp_replace(d.number, '[0-9]+$', '') AS prefix
                FROM documents d
                JOIN numbering_series s ON s.establishment_id = d.establishment_id AND s.document_type = d.document_type AND s.is_default
                WHERE d.number IS NOT NULL AND s.format ~ '\\{SEQ(:[0-9]+)?\\}$'
            ),
            begun AS (
                SELECT q.*, MIN(q.issue_date) OVER (PARTITION BY q.company_id, q.establishment_id, q.document_type, q.prefix) AS prefix_began
                FROM sequenced q
            ),
            numbered AS (
                SELECT b.company_id, b.establishment_id, b.document_type, b.number, b.issue_date, b.sequence,
                       LAG(b.sequence) OVER issued AS previous,
                       LAG(b.issue_date) OVER issued AS previous_day,
                       ROW_NUMBER() OVER issued AS nth
                FROM begun b
                -- The order issuing gave them, which the day alone no longer tells once an operator allowed earlier days:
                -- what the number prints before its sequence (its period, which a resetting series must print) in the
                -- order each was first used, then the sequence.
                WINDOW issued AS (PARTITION BY b.company_id, b.establishment_id, b.document_type ORDER BY b.prefix_began, b.prefix, b.sequence, b.id)
            )
            SELECT company_id, establishment_id, document_type, number, previous
            FROM numbered
            WHERE sequence IS NULL
               OR (nth > 1 AND previous IS NOT NULL AND sequence <> previous + 1
                   AND NOT (sequence = 1 AND date_trunc('month', issue_date) <> date_trunc('month', previous_day)))
            ORDER BY company_id, establishment_id, document_type, issue_date, number
            SQL);
        foreach ($rows as $row) {
            yield new DataFinding($check, Uuid::fromString(Rows::text($row['company_id'])), \sprintf(
                '%s %s of establishment %s does not follow %s',
                Rows::text($row['document_type']),
                Rows::text($row['number']),
                Rows::text($row['establishment_id']),
                null === $row['previous'] ? 'anything: its sequence cannot be read' : Rows::text($row['previous']),
            ));
        }
    }
}

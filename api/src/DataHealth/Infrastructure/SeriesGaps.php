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
            numbered AS (
                SELECT d.company_id, d.establishment_id, d.document_type, d.number, d.issue_date,
                       substring(d.number FROM '([0-9]+)$')::bigint AS sequence,
                       LAG(substring(d.number FROM '([0-9]+)$')::bigint) OVER issued AS previous,
                       LAG(d.issue_date) OVER issued AS previous_day,
                       ROW_NUMBER() OVER issued AS nth
                FROM documents d
                JOIN numbering_series s ON s.establishment_id = d.establishment_id AND s.document_type = d.document_type AND s.is_default
                WHERE d.number IS NOT NULL AND s.format ~ '\\{SEQ(:[0-9]+)?\\}$'
                -- The order issuing gave them: by day, then by number within it, the shorter first where it is not padded.
                WINDOW issued AS (PARTITION BY d.company_id, d.establishment_id, d.document_type ORDER BY d.issue_date, LENGTH(d.number), d.number, d.id)
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

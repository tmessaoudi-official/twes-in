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
 * Documents numbered without the journal saying so: the use case numbering a document records it in the same
 * transaction, so a numbered document whose entry is missing was numbered by a direct write or a bug, or came back from
 * a restore without the journal that recorded it.
 */
final readonly class UnrecordedDocuments
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param string $documents a query of the context's own numbered documents, naming `company_id`, `id` and `number`
     * @param string $entity    the entity type the journal records them under
     * @param string $action    the action numbering them records
     *
     * @return iterable<DataFinding>
     */
    public function findings(string $check, string $documents, string $entity, string $action): iterable
    {
        $rows = $this->connection->iterateAssociative(<<<SQL
            WITH documents AS ({$documents})
            SELECT d.company_id, d.number
            FROM documents d
            WHERE d.number IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.entity_type = :entity AND a.entity_id = d.id AND a.action = :action)
            ORDER BY d.company_id, d.number
            SQL, ['entity' => $entity, 'action' => $action]);
        foreach ($rows as $row) {
            yield new DataFinding($check, Uuid::fromString(Rows::text($row['company_id'])), \sprintf('%s %s has no %s in the journal', $entity, Rows::text($row['number']), $action));
        }
    }
}

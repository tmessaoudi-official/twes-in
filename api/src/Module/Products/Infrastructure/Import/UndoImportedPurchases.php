<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Import;

use App\ImportExport\Application\Undo\UndoesImportedRows;
use App\Module\Products\Domain\ValueObject\CostChangeSource;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Takes back the past purchases a file put in products' cost histories. They are history that nothing builds on, so
 * every one the file wrote goes; one gone since with its product is not looked for. Only a purchase line is ever taken,
 * never a change of a cost, whatever the run noted.
 */
final readonly class UndoImportedPurchases implements UndoesImportedRows
{
    public function __construct(private Connection $connection)
    {
    }

    public function tables(): array
    {
        return ['product_cost_change'];
    }

    public function undo(Company $company, array $created, ?Uuid $actorUserId): array
    {
        $ids = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $created['product_cost_change'] ?? []);
        if ([] !== $ids) {
            $this->connection->executeStatement(
                'DELETE FROM product_cost_change WHERE company_id = :company AND source = :purchase AND id = ANY(CAST(:ids AS uuid[]))',
                ['company' => $company->getId()->toRfc4122(), 'purchase' => CostChangeSource::Purchase->value, 'ids' => '{'.implode(',', $ids).'}'],
            );
        }

        return [];
    }
}

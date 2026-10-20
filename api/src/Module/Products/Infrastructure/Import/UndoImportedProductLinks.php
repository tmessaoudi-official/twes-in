<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Import;

use App\ImportExport\Application\Undo\UndoesImportedRows;
use App\Module\Products\Application\ManageProducts;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Takes back what a file of who sells what added to products: the links to vendors and the vendors' own codes, product
 * by product, through the products' own rules. A row gone since, by hand or with its product, is not looked for.
 */
final readonly class UndoImportedProductLinks implements UndoesImportedRows
{
    public function __construct(
        private ManageProducts $manage,
        private Connection $connection,
    ) {
    }

    public function tables(): array
    {
        return ['product_vendor', 'product_barcode'];
    }

    public function undo(Company $company, array $created, ?Uuid $actorUserId): array
    {
        $byProduct = [];
        foreach (['product_vendor' => 'links', 'product_barcode' => 'barcodes'] as $table => $kind) {
            $ids = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $created[$table] ?? []);
            if ([] === $ids) {
                continue;
            }
            // The table is one of the two above, never a value sent.
            foreach ($this->connection->fetchAllAssociative(
                'SELECT id::text AS id, product_id::text AS product_id FROM '.$table.' WHERE company_id = :company AND id = ANY(CAST(:ids AS uuid[]))',
                ['company' => $company->getId()->toRfc4122(), 'ids' => '{'.implode(',', $ids).'}'],
            ) as $row) {
                if (!\is_string($row['id']) || !\is_string($row['product_id'])) {
                    throw new \LogicException('A product\'s row names its id and its product.');
                }
                $byProduct[$row['product_id']][$kind][] = Uuid::fromString($row['id']);
            }
        }
        ksort($byProduct);

        $kept = [];
        foreach ($byProduct as $productId => $rows) {
            array_push($kept, ...$this->manage->takeBackImported($company, Uuid::fromString($productId), $rows['links'] ?? [], $rows['barcodes'] ?? [], $actorUserId));
        }

        return $kept;
    }
}

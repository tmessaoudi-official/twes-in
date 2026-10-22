<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Inventory;

use App\Module\Inventory\Application\Merge\MergedProducts;
use App\Shared\Infrastructure\Doctrine\KeptRecords;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Read from the table on the request's one connection, inside whatever transaction and locks the caller holds. */
final readonly class DbalMergedProducts implements MergedProducts
{
    public function __construct(private Connection $connection)
    {
    }

    public function mergedAway(array $productIds): array
    {
        $kept = [];
        foreach ($this->connection->fetchAllKeyValue(
            'SELECT p.id::text, k.reference FROM product p JOIN product k ON k.id = '.KeptRecords::id('p').' WHERE p.id = ANY(CAST(:ids AS uuid[])) AND '.KeptRecords::mergedAway('p'),
            ['ids' => '{'.implode(',', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $productIds)).'}'],
        ) as $id => $reference) {
            $kept[Rows::text($id)] = Rows::text($reference);
        }

        return $kept;
    }
}

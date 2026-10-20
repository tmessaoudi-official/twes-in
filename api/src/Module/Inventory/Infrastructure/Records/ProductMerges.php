<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Records;

use App\Records\Application\Merge\MergeEffects;
use App\Records\Application\Merge\MergeRefused;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * A product's stock is what its movements add up to, which no row a merge points elsewhere carries: until a merge moves
 * goods on hand across, a product holding any is not merged away.
 */
final readonly class ProductMerges implements MergeEffects
{
    public function __construct(private Connection $connection)
    {
    }

    public function subject(): string
    {
        return 'product';
    }

    public function merging(Uuid $companyId, Uuid $mergeId, Uuid $keptId, array $mergedIds, Uuid $actorUserId, \DateTimeImmutable $at): void
    {
        $holding = $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM stock_movement WHERE company_id = :company AND product_id = ANY(CAST(:ids AS uuid[])) GROUP BY product_id, location_id, lot_id HAVING sum(quantity) <> 0)',
            ['company' => $companyId->toRfc4122(), 'ids' => '{'.implode(',', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $mergedIds)).'}'],
        );
        if (true === $holding) {
            throw new MergeRefused('stock_on_hand', 'A product merged away holds goods: count them out, or move them, first.');
        }
    }

    public function unmerging(Uuid $companyId, Uuid $mergeId, Uuid $keptId, array $mergedIds, ?Uuid $actorUserId, \DateTimeImmutable $at): void
    {
    }
}

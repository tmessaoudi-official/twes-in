<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Records;

use App\Module\Inventory\Application\Merge\CarryMergedStock;
use App\Module\Inventory\Application\Merge\StockNotCarried;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Records\Application\Merge\MergeActor;
use App\Records\Application\Merge\MergeEffects;
use App\Records\Application\Merge\MergeRefused;
use Symfony\Component\Uid\Uuid;

/**
 * A product's stock is what its movements add up to, which no row a merge points elsewhere carries: the goods a product
 * merged away holds go to the product kept by movements of their own, and come back when the merge is undone.
 */
final readonly class ProductMerges implements MergeEffects
{
    public function __construct(private CarryMergedStock $stock)
    {
    }

    public function subject(): string
    {
        return 'product';
    }

    public function merging(Uuid $companyId, Uuid $mergeId, Uuid $keptId, array $mergedIds, MergeActor $actor, \DateTimeImmutable $at): void
    {
        try {
            $this->stock->carry($companyId, $mergeId, $keptId, $mergedIds, $actor->may(ProductPermission::COST_READ), $actor->id);
        } catch (StockNotCarried $refused) {
            throw new MergeRefused($refused->reason, $refused->getMessage(), $refused->params);
        }
    }

    public function unmerging(Uuid $companyId, Uuid $mergeId, Uuid $keptId, array $mergedIds, ?Uuid $actorUserId, \DateTimeImmutable $at): void
    {
        $this->stock->carryBack($companyId, $mergeId, $keptId, $mergedIds, $actorUserId);
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The stock a product file brings, as this module needs it: a row may add goods
 * or count them where the product sits. Declared here and answered by the inventory, as `ProductHomes` is: stock
 * depends on the catalogue and not the other way round. Each change is a movement marked as the import's.
 */
interface ProductFileStock
{
    /** Whether a file of this company, sent by this person, may carry quantities: the stock kept, and theirs to write. */
    public function offered(Company $company): bool;

    /**
     * Goods added where the row says, or where the product can only mean: `$locationCode`, else `$homeLocationId`,
     * else the product's home, else the only establishment's default place.
     *
     * @throws ProductStockRefused
     */
    public function add(Company $company, Uuid $productId, ?string $locationCode, ?Uuid $homeLocationId, string $quantity, ?string $unitCost, ?Uuid $actorUserId, Uuid $runId): ProductStockChange;

    /**
     * What is there, at the same place; refused where goods moved since the last count unless `$recount`.
     *
     * @throws ProductStockRefused
     */
    public function count(Company $company, Uuid $productId, ?string $locationCode, ?Uuid $homeLocationId, string $counted, bool $recount, ?Uuid $actorUserId, Uuid $runId): ProductStockChange;

    /** Once the file is committed: its alerts, raised once over all it moved, and one word to the other keepers. */
    public function finished(Company $company, Uuid $runId, ?Uuid $actorUserId): void;
}

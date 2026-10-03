<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use Symfony\Component\Uid\Uuid;

interface ProductCostChangeRepository
{
    public function save(ProductCostChange $change): void;

    /** @return list<ProductCostChange> a product's changes of cost in its company, the newest first */
    public function ofProduct(Uuid $productId, Uuid $companyId): array;
}

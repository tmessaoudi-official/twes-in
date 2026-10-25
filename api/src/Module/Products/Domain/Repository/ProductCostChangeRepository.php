<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain\Repository;

use App\Module\Products\Domain\Model\ProductCostChange;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use Symfony\Component\Uid\Uuid;

interface ProductCostChangeRepository
{
    public function save(ProductCostChange $change): void;

    /** @return list<ProductCostChange> the latest changes of a product's cost in its company, the newest first */
    public function ofProduct(Uuid $productId, Uuid $companyId, int $limit): array;

    /**
     * A page of the past purchases made from a vendor in its company, each with its product: the newest day first, the
     * undated after every dated one, as a product's history orders them.
     *
     * @return Page<ProductCostChange>
     */
    public function purchasesFromVendor(Uuid $vendorId, Uuid $companyId, PageRequest $request): Page;
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Products\Domain\ProductCostChange;
use App\Module\Products\Domain\ProductCostChangeRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryProductCostChanges implements ProductCostChangeRepository
{
    /** @var list<ProductCostChange> */
    public array $changes = [];

    public function save(ProductCostChange $change): void
    {
        $this->changes[] = $change;
    }

    public function ofProduct(Uuid $productId, Uuid $companyId, int $limit): array
    {
        return \array_slice(array_values(array_reverse(array_filter(
            $this->changes,
            static fn (ProductCostChange $change): bool => $change->getProduct()->getId()->equals($productId) && $change->getProduct()->getCompany()->getId()->equals($companyId),
        ))), 0, $limit);
    }
}

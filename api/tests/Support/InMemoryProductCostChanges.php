<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Products\Domain\Model\ProductCostChange;
use App\Module\Products\Domain\Repository\ProductCostChangeRepository;
use App\Module\Products\Domain\ValueObject\CostChangeSource;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
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

    public function purchasesFromVendor(Uuid $vendorId, Uuid $companyId, PageRequest $request): Page
    {
        $purchases = array_values(array_filter(
            $this->changes,
            static fn (ProductCostChange $change): bool => CostChangeSource::Purchase === $change->getSource()
                && true === $change->getVendor()?->getId()->equals($vendorId)
                && $change->getProduct()->getCompany()->getId()->equals($companyId),
        ));
        // The newest day first, those of no known day last, the latest written first among equals.
        $written = array_flip(array_map(static fn (ProductCostChange $change): int => spl_object_id($change), $purchases));
        usort($purchases, static fn (ProductCostChange $a, ProductCostChange $b): int => [null === $a->getPurchasedOn(), $b->getPurchasedOn(), $written[spl_object_id($b)]]
            <=> [null === $b->getPurchasedOn(), $a->getPurchasedOn(), $written[spl_object_id($a)]]);

        return new Page(\array_slice($purchases, $request->offset(), $request->size), \count($purchases), $request);
    }
}

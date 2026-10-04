<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Inventory\Domain\ProductHomeLocation;
use App\Module\Inventory\Domain\ProductHomeLocationRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryProductHomeLocations implements ProductHomeLocationRepository
{
    /** @var list<ProductHomeLocation> */
    public array $homes = [];

    public function ofProduct(Uuid $productId, Uuid $companyId): array
    {
        $mine = array_values(array_filter(
            $this->homes,
            static fn (ProductHomeLocation $h): bool => $h->getProduct()->getId()->equals($productId)
                && $h->getCompany()->getId()->equals($companyId),
        ));
        usort($mine, static fn (ProductHomeLocation $a, ProductHomeLocation $b) => [$a->getEstablishment()->getCode(), $a->getPosition()] <=> [$b->getEstablishment()->getCode(), $b->getPosition()]);

        return $mine;
    }

    public function ofProductInEstablishment(Uuid $productId, Uuid $establishmentId): array
    {
        $found = array_values(array_filter(
            $this->homes,
            static fn (ProductHomeLocation $h): bool => $h->getProduct()->getId()->equals($productId) && $h->getEstablishment()->getId()->equals($establishmentId),
        ));
        usort($found, static fn (ProductHomeLocation $a, ProductHomeLocation $b): int => $a->getPosition() <=> $b->getPosition());

        return $found;
    }

    public function ofProducts(array $productIds, Uuid $companyId): array
    {
        $wanted = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $productIds);
        $byProduct = [];
        foreach ($this->homes as $home) {
            $productId = $home->getProduct()->getId()->toRfc4122();
            if (\in_array($productId, $wanted, true) && $home->getCompany()->getId()->equals($companyId)) {
                $byProduct[$productId][] = $home;
            }
        }

        return $byProduct;
    }

    public function save(ProductHomeLocation $home): void
    {
        foreach ($this->homes as $known) {
            if ($known->getId()->equals($home->getId())) {
                return;
            }
        }
        $this->homes[] = $home;
    }

    public function remove(ProductHomeLocation $home): void
    {
        $this->homes = array_values(array_filter(
            $this->homes,
            static fn (ProductHomeLocation $h): bool => !$h->getId()->equals($home->getId()),
        ));
    }
}

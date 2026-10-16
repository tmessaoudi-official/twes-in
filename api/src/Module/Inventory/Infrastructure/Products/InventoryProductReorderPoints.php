<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Products;

use App\Module\Inventory\Application\KeepReorderPoints;
use App\Module\Inventory\Domain\Exception\InvalidReorderPoint;
use App\Module\Inventory\Domain\Repository\ProductReorderPointRepository;
use App\Module\Inventory\Domain\Repository\StockLocationRepository;
use App\Module\Products\Application\Stock\ProductReorderPoints;
use App\Module\Products\Application\Stock\ReorderPointRefused;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Answers the catalogue's `ProductReorderPoints` port out of this module. The point is dropped from the unit of work
 * once written, for the reason `InventoryProductHomes` gives: the one caller is an import, which detaches each
 * product after its row, and a point left managed would make the next row's flush take that product for a new one.
 */
final readonly class InventoryProductReorderPoints implements ProductReorderPoints
{
    public function __construct(
        private StockLocationRepository $locations,
        private KeepReorderPoints $points,
        private ProductReorderPointRepository $held,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function establishmentOfLocation(Company $company, Uuid $locationId): ?Uuid
    {
        return $this->locations->ofIdInCompany($locationId, $company->getId())?->getEstablishment()->getId();
    }

    public function setReorderPoint(Company $company, Uuid $productId, Uuid $establishmentId, string $quantity, ?Uuid $actorUserId): array
    {
        // Read before it is written: the point kept is the same object, changed in place.
        $before = $this->held->ofProductInEstablishment($productId, $establishmentId)?->getQuantity() ?? '';
        try {
            $point = $this->points->set($company, $productId, $establishmentId, $quantity, $actorUserId);
            $after = $point->getQuantity();
            $this->entityManager->detach($point);

            return [$before, $after];
        } catch (InvalidReorderPoint $refused) {
            throw new ReorderPointRefused($refused->getMessage(), 0, $refused);
        }
    }
}

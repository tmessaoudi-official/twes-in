<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Purchases;

use App\Module\Inventory\Domain\Model\StockLocation;
use App\Module\Inventory\Domain\Repository\ProductHomeLocationRepository;
use App\Module\Inventory\Domain\Repository\StockLocationRepository;
use App\Module\Purchases\Application\ReceivingPlace;
use App\Module\Purchases\Application\ReceivingPlaces;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** Answers the purchases module's `ReceivingPlaces` port out of this module's places and the products' homes. */
final readonly class PlacesForOrders implements ReceivingPlaces
{
    public function __construct(private StockLocationRepository $locations, private ProductHomeLocationRepository $homes)
    {
    }

    public function placesOf(Company $company, Uuid $establishmentId): array
    {
        $places = array_filter(
            $this->locations->ofCompany($company->getId()),
            static fn (StockLocation $location): bool => $location->getEstablishment()->getId()->equals($establishmentId),
        );

        return array_values(array_map(static fn (StockLocation $location): ReceivingPlace => new ReceivingPlace(
            $location->getId(),
            $location->getParent()?->getId(),
            $location->getCode(),
            $location->getName(),
            $location->isDefault(),
        ), $places));
    }

    public function homesOf(Company $company, Uuid $establishmentId, array $productIds): array
    {
        if ([] === $productIds) {
            return [];
        }
        $found = [];
        foreach ($this->homes->ofProducts($productIds, $company->getId()) as $productId => $homes) {
            foreach ($homes as $home) {
                if ($home->getEstablishment()->getId()->equals($establishmentId)) {
                    $found[$productId] = $home->getLocation()->getId();
                    break;
                }
            }
        }

        return $found;
    }
}

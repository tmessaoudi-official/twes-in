<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Domain\StockLocationRepository;
use App\Module\Products\Domain\ProductRepository;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<StockLevelResource> */
final readonly class StockLevelCollectionProvider implements ProviderInterface
{
    public function __construct(
        private KeepStock $stock,
        private ProductRepository $products,
        private StockLocationRepository $locations,
        private CompanyGuard $guard,
    ) {
    }

    /** @return list<StockLevelResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);
        $products = [];
        foreach ($this->products->ofCompany($company->getId()) as $product) {
            $products[$product->getId()->toRfc4122()] = $product;
        }
        $locations = [];
        foreach ($this->locations->ofCompany($company->getId()) as $location) {
            $locations[$location->getId()->toRfc4122()] = $location;
        }

        $rows = [];
        foreach ($this->stock->levels($company) as $level) {
            $product = $products[$level->productId->toRfc4122()] ?? null;
            $location = $locations[$level->locationId->toRfc4122()] ?? null;
            if (null === $product || null === $location) {
                continue;
            }
            $row = new StockLevelResource();
            $row->productId = $product->getId()->toRfc4122();
            $row->productReference = $product->getReference();
            $row->productName = $product->getDetails()->name;
            $row->unitCode = $product->getUnit()->getCode();
            $row->locationId = $location->getId()->toRfc4122();
            $row->locationCode = $location->getCode();
            $row->locationName = $location->getName();
            $row->establishmentId = $location->getEstablishment()->getId()->toRfc4122();
            $row->quantity = $level->quantity;
            $rows[] = $row;
        }
        usort($rows, static fn (StockLevelResource $a, StockLevelResource $b): int => [$a->productReference, $a->locationCode] <=> [$b->productReference, $b->locationCode]);

        return $rows;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\FindOnMap;
use App\Module\Inventory\Application\MapHolding;
use App\Module\Products\Domain\ProductRepository;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * A product of no company of ours has no stock of ours either, so it is answered as found nowhere rather than 404:
 * what is asked is where, not whether it exists.
 *
 * @implements ProviderInterface<StockWhereaboutResource>
 */
final readonly class StockWhereaboutCollectionProvider implements ProviderInterface
{
    public function __construct(private FindOnMap $map, private ProductRepository $products, private CompanyGuard $guard)
    {
    }

    /** @return list<StockWhereaboutResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);
        $productId = Paging::identifier($operation, 'productId');
        $product = null === $productId ? null : $this->products->ofIdInCompany($productId, $company->getId());
        if (null === $product) {
            return [];
        }

        return array_map(static fn (MapHolding $holding): StockWhereaboutResource => StockWhereaboutResource::of($holding, $product), $this->map->of($company, $product->getId()));
    }
}

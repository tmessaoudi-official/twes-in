<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Purchases\Application\OrderProduct;
use App\Module\Purchases\Application\OrderProducts;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\PurchaseOrderProductPickResource;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProviderInterface<PurchaseOrderProductPickResource> */
final readonly class PurchaseOrderProductPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private OrderProducts $products)
    {
    }

    /** @return list<PurchaseOrderProductPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), PurchaseOrderPermission::READ);
        $seesCost = $this->guard->may($company, ProductPermission::COST_READ);
        $ids = Paging::uuids($operation, 'ids');

        return array_map(
            static fn (OrderProduct $product): PurchaseOrderProductPickResource => PurchaseOrderProductPickResource::of($product, $seesCost),
            [] === $ids ? $this->products->matching($company, Paging::text($operation) ?? '') : $this->products->ofIds($company, $ids),
        );
    }
}

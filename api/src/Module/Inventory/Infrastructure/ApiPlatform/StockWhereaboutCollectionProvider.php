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
use App\Module\Products\Domain\ProductRepository;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * A product of no company of ours has no stock of ours either, so it is answered as found nowhere rather than 404:
 * what is asked is where, not whether it exists. A product named twice is answered once.
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
        $asked = Paging::uuids($operation, 'productId');
        $products = [];
        foreach ($this->products->ofIdsInCompany($asked, $company->getId()) as $product) {
            $products[$product->getId()->toRfc4122()] = $product;
        }
        // In the order asked, each once: a note naming one product on two lines finds it once.
        $ids = [];
        foreach ($asked as $id) {
            if (isset($products[$id->toRfc4122()])) {
                $ids[$id->toRfc4122()] = $id;
            }
        }

        $rows = [];
        foreach ($this->map->of($company, array_values($ids)) as $productId => $holdings) {
            foreach ($holdings as $holding) {
                $rows[] = StockWhereaboutResource::of($holding, $products[$productId]);
            }
        }

        return $rows;
    }
}

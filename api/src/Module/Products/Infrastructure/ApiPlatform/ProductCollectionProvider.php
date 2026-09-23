<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductSearch;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * One page of a company's products, searched, narrowed and sorted in the database (docs/SPEC.md § 7, lists at scale).
 *
 * @implements ProviderInterface<ProductResource>
 */
final readonly class ProductCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageProducts $manage, private CompanyGuard $guard, private Paging $paging)
    {
    }

    /** @return TraversablePaginator<ProductResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);
        $kind = Paging::value($operation, 'kind');
        $active = Paging::value($operation, 'isActive');
        $search = new ProductSearch(
            Paging::text($operation),
            \is_string($kind) ? ProductKind::from($kind) : null,
            \is_bool($active) ? $active : null,
            Paging::order($operation, ProductSearch::SORTS),
        );

        $withCosts = $this->guard->may($company, ProductPermission::COST_READ);

        return $this->paging->paginator(
            $this->manage->search($company, $search, $this->paging->request($operation, $context)),
            static fn (Product $product): ProductResource => ProductResource::of($product, $withCosts),
        );
    }
}

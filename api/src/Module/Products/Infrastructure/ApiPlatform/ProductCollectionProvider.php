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
use App\Module\Products\Application\ProductPhotos;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductSearch;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\Uid\Uuid;

/**
 * One page of a company's products, searched, narrowed and sorted in the database (docs/SPEC.md § 7, lists at scale).
 *
 * @implements ProviderInterface<ProductResource>
 */
final readonly class ProductCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageProducts $manage, private CompanyGuard $guard, private Paging $paging, private ProductPhotos $photos)
    {
    }

    /** @return TraversablePaginator<ProductResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);
        $search = ProductSearchReader::read(Paging::parameters($context), Paging::text($operation), Paging::order($operation, ProductSearch::SORTS));

        $withCosts = $this->guard->may($company, ProductPermission::COST_READ);

        $page = $this->manage->search($company, $search, $this->paging->request($operation, $context));
        // The page's main photos in one read rather than one per row.
        $photos = $this->photos->mainPhotoIdsOf($company, array_map(static fn (Product $product): Uuid => $product->getId(), $page->items));

        return $this->paging->paginator(
            $page,
            static fn (Product $product): ProductResource => ProductResource::of($product, $withCosts, $photos[$product->getId()->toRfc4122()] ?? null),
        );
    }
}

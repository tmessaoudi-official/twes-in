<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Application\Photo\ProductPhotos;
use App\Module\Products\Application\ProductCheckScopes;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductSearch;
use App\Module\Products\Infrastructure\ApiPlatform\Payload\ProductSearchReader;
use App\Module\Products\Infrastructure\ApiPlatform\Resource\ProductResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\Uid\Uuid;

/**
 * One page of a company's products, searched, narrowed and sorted in the database (docs/SPEC.md § 7, lists at scale).
 *
 * @implements ProviderInterface<ProductResource>
 */
final readonly class ProductCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageProducts $manage, private CompanyGuard $guard, private Paging $paging, private ProductPhotos $photos, private CurrencyScales $scales, private ProductCheckScopes $checkScopes)
    {
    }

    /** @return TraversablePaginator<ProductResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);
        $search = ProductSearchReader::read(Paging::parameters($context), Paging::text($operation), Paging::order($operation, ProductSearch::SORTS));

        $withCosts = $this->guard->may($company, ProductPermission::COST_READ);
        $search = $this->checkScopes->scoped($company, $search, $withCosts);
        $scope = $search->checkScope ?? $this->checkScopes->of($company, $withCosts);
        $scale = $this->scales->of($company->getCurrency());

        $page = $this->manage->search($company, $search, $this->paging->request($operation, $context));
        // The page's main photos in one read rather than one per row.
        $photos = $this->photos->mainPhotoIdsOf($company, array_map(static fn (Product $product): Uuid => $product->getId(), $page->items));

        return $this->paging->paginator(
            $page,
            static fn (Product $product): ProductResource => ProductResource::of($product, $scope, $scale, $photos[$product->getId()->toRfc4122()] ?? null),
        );
    }
}

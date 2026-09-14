<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Application\ManageProductCategories;
use App\Module\Products\Domain\ProductCategory;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<ProductCategoryResource> */
final readonly class ProductCategoryCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageProductCategories $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<ProductCategoryResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);

        return array_map(fn (ProductCategory $category) => ProductCategoryResource::of($category, $this->manage->productCount($category), $this->manage->childCount($category)), $this->manage->list($company));
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Application\Category\ManageProductCategories;
use App\Module\Products\Domain\Model\ProductCategory;
use App\Module\Products\Infrastructure\ApiPlatform\Resource\ProductCategoryResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Records\Application\Links\ConfigurationLinks;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProviderInterface<ProductCategoryResource> */
final readonly class ProductCategoryCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageProductCategories $manage, private CompanyGuard $guard, private ConfigurationLinks $links)
    {
    }

    /** @return list<ProductCategoryResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);

        $cleared = $this->links->cleared($company);

        return array_map(fn (ProductCategory $category) => ProductCategoryResource::of($category, $this->manage->productCount($category), $this->manage->childCount($category), $cleared), $this->manage->list($company));
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Domain\Product;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<ProductResource> */
final readonly class ProductCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageProducts $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<ProductResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);

        return array_map(static fn (Product $product) => ProductResource::of($product), $this->manage->list($company));
    }
}

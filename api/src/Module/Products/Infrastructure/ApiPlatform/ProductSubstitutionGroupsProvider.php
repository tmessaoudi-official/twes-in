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
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<ProductSubstitutionGroupResource> */
final readonly class ProductSubstitutionGroupsProvider implements ProviderInterface
{
    public function __construct(private ManageProducts $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<ProductSubstitutionGroupResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);

        return array_map(static function (array $group): ProductSubstitutionGroupResource {
            $resource = new ProductSubstitutionGroupResource();
            $resource->name = $group['name'];
            $resource->products = $group['products'];

            return $resource;
        }, $this->manage->substitutionGroups($company));
    }
}

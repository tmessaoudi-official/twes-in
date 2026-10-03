<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\ReadAvailability;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<CustomerScreenAvailabilityResource> */
final readonly class CustomerScreenAvailabilityProvider implements ProviderInterface
{
    public function __construct(private ReadAvailability $availability, private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerScreenAvailabilityResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);

        $resource = new CustomerScreenAvailabilityResource();
        $resource->items = $this->availability->among($company, \array_slice(Paging::uuids($operation, 'ids'), 0, 20));

        return $resource;
    }
}

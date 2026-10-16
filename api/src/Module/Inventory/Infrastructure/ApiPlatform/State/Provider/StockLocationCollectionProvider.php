<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\Location\ManageStockLocations;
use App\Module\Inventory\Domain\Model\StockLocation;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\StockLocationResource;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProviderInterface<StockLocationResource> */
final readonly class StockLocationCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageStockLocations $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<StockLocationResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);

        return array_map(fn (StockLocation $location) => StockLocationResource::of($location, $this->manage->childCount($location), $this->manage->movementCount($location)), $this->manage->list($company));
    }
}

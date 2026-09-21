<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\DrawStockMap;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<StockFloorResource> */
final readonly class StockFloorCollectionProvider implements ProviderInterface
{
    public function __construct(private DrawStockMap $map, private CompanyGuard $guard)
    {
    }

    /** @return list<StockFloorResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);

        $floors = [];
        foreach ($this->map->floors($company) as [$area, $drawn]) {
            $floors[] = StockFloorResource::of($area, \count($drawn));
        }

        return $floors;
    }
}

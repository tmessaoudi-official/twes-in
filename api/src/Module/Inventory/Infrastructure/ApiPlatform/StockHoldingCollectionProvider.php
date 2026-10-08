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
use App\Module\Inventory\Application\FindOnMap;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use App\Venue\Application\VenueAreaNotFound;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<StockHoldingResource> */
final readonly class StockHoldingCollectionProvider implements ProviderInterface
{
    public function __construct(private DrawStockMap $map, private FindOnMap $find, private CompanyGuard $guard)
    {
    }

    /** @return list<StockHoldingResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);

        try {
            // The floor's own drawn places: an unknown floor answers 404 here, as its drawings do.
            $drawn = $this->map->drawingsOf($company, CompanyPath::identifier($uriVariables, 'floorId'));
        } catch (VenueAreaNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        $holdings = [];
        foreach ($this->find->countsAt($company, $drawn) as $locationId => $products) {
            $holdings[] = StockHoldingResource::of($locationId, $products);
        }

        return $holdings;
    }
}

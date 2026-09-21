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
use App\Module\Inventory\Domain\StockLocation;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use App\Venue\Application\VenueAreaNotFound;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<StockDrawingResource> */
final readonly class StockDrawingCollectionProvider implements ProviderInterface
{
    public function __construct(private DrawStockMap $map, private CompanyGuard $guard)
    {
    }

    /** @return list<StockDrawingResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);

        try {
            $drawn = $this->map->drawingsOf($company, CompanyPath::identifier($uriVariables, 'floorId'));
        } catch (VenueAreaNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return array_map(static fn (StockLocation $location): StockDrawingResource => StockDrawingResource::ofDrawn($location), $drawn);
    }
}

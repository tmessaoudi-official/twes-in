<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use App\Venue\Application\ArrangeVenue;
use App\Venue\Application\VenueAreaNotFound;
use App\Venue\Domain\VenueStructure;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<StockStructureResource> */
final readonly class StockStructureCollectionProvider implements ProviderInterface
{
    public function __construct(private ArrangeVenue $venue, private CompanyGuard $guard)
    {
    }

    /** @return list<StockStructureResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);

        try {
            $built = $this->venue->structuresOf($company, CompanyPath::identifier($uriVariables, 'floorId'));
        } catch (VenueAreaNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return array_map(static fn (VenueStructure $piece): StockStructureResource => StockStructureResource::of($piece), $built);
    }
}

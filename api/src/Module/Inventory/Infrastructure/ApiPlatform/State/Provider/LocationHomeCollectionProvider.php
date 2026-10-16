<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\KeepProductHomes;
use App\Module\Inventory\Domain\Exception\InvalidStockLocation;
use App\Module\Inventory\Domain\Model\ProductHomeLocation;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\LocationHomeResource;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The place is what the path addresses, so one this company does not have is a 404, like any other address.
 *
 * @implements ProviderInterface<LocationHomeResource>
 */
final readonly class LocationHomeCollectionProvider implements ProviderInterface
{
    public function __construct(private KeepProductHomes $homes, private CompanyGuard $guard)
    {
    }

    /** @return list<LocationHomeResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);

        try {
            $homes = $this->homes->at($company, CompanyPath::identifier($uriVariables, 'locationId'));
        } catch (InvalidStockLocation $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return array_map(static fn (ProductHomeLocation $home): LocationHomeResource => LocationHomeResource::of($home), $homes);
    }
}

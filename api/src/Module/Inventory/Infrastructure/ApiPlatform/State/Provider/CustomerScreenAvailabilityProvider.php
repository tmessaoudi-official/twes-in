<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\ReadAvailability;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\CustomerScreenAvailabilityResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Application\Establishment\EstablishmentNotFound;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        $establishment = Paging::identifier($operation, 'establishmentId');
        try {
            $resource->items = $this->availability->among($company, \array_slice(Paging::uuids($operation, 'ids'), 0, 20), $establishment);
        } catch (EstablishmentNotFound $absent) {
            throw new NotFoundHttpException('No such establishment.', $absent);
        }

        return $resource;
    }
}

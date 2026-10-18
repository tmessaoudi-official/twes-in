<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Purchases\Application\ManagePurchaseOrders;
use App\Module\Purchases\Infrastructure\ApiPlatform\Payload\PurchaseOrderSearchReader;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\PurchaseOrderStatusCountsResource;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProviderInterface<PurchaseOrderStatusCountsResource> */
final readonly class PurchaseOrderStatusCountsProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ManagePurchaseOrders $manage)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderStatusCountsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), PurchaseOrderPermission::READ);

        return PurchaseOrderStatusCountsResource::of($this->manage->statusCounts(
            $company,
            PurchaseOrderSearchReader::read(Paging::parameters($context), Paging::text($operation), [])->withoutStatus(),
        ));
    }
}

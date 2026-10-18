<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Purchases\Application\OrderVendors;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\PurchaseOrderVendorPickResource;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProviderInterface<PurchaseOrderVendorPickResource> */
final readonly class PurchaseOrderVendorPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private OrderVendors $vendors)
    {
    }

    /** @return list<PurchaseOrderVendorPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), PurchaseOrderPermission::READ);
        $ids = Paging::uuids($operation, 'ids');

        return array_map(PurchaseOrderVendorPickResource::of(...), [] === $ids
            ? $this->vendors->matching($company, Paging::text($operation) ?? '')
            : $this->vendors->ofIds($company, $ids));
    }
}

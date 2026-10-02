<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\PriceLists\Application\ManagePriceLists;
use App\Module\PriceLists\Domain\PriceList;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<PriceListResource> */
final readonly class PriceListCollectionProvider implements ProviderInterface
{
    public function __construct(private ManagePriceLists $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<PriceListResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);

        return array_map(static fn (PriceList $list) => PriceListResource::of($list, false), $this->manage->list($company));
    }
}

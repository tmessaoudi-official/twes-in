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
use App\Module\PriceLists\Application\PriceListNotFound;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<PriceListResource> */
final readonly class PriceListItemProvider implements ProviderInterface
{
    public function __construct(private ManagePriceLists $manage, private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PriceListResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);

        try {
            return PriceListResource::of($this->manage->get($company, CompanyPath::identifier($uriVariables, 'priceListId')), true);
        } catch (PriceListNotFound $absent) {
            throw new NotFoundHttpException('No such price list.', $absent);
        }
    }
}

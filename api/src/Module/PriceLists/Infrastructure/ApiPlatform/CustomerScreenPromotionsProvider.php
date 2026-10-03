<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\PriceLists\Application\OpenPromotions;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Psr\Clock\ClockInterface;

/** @implements ProviderInterface<CustomerScreenPromotionsResource> */
final readonly class CustomerScreenPromotionsProvider implements ProviderInterface
{
    public function __construct(private OpenPromotions $promotions, private CompanyGuard $guard, private ClockInterface $clock)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerScreenPromotionsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);
        $today = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'));

        $resource = new CustomerScreenPromotionsResource();
        $resource->items = $this->promotions->among($company, \array_slice(Paging::uuids($operation, 'ids'), 0, 20), $today);

        return $resource;
    }
}

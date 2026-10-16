<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Application\ProductNotFound;
use App\Module\Products\Domain\Model\ProductCostChange;
use App\Module\Products\Infrastructure\ApiPlatform\Resource\ProductCostChangeResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<ProductCostChangeResource> */
final readonly class ProductCostHistoryProvider implements ProviderInterface
{
    public function __construct(private ManageProducts $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<ProductCostChangeResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::COST_READ);

        try {
            return array_map(static fn (ProductCostChange $change): ProductCostChangeResource => ProductCostChangeResource::of($change), $this->manage->costHistoryOf($company, CompanyPath::identifier($uriVariables, 'productId')));
        } catch (ProductNotFound $absent) {
            throw new NotFoundHttpException('No such product.', $absent);
        }
    }
}

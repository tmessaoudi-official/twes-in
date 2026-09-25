<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\KeepReorderPoints;
use App\Module\Inventory\Application\ProductNotInCompany;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<ProductReorderPointResource> */
final readonly class ProductReorderPointCollectionProvider implements ProviderInterface
{
    public function __construct(private KeepReorderPoints $points, private CompanyGuard $guard)
    {
    }

    /** @return list<ProductReorderPointResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);

        try {
            $points = $this->points->of($company, CompanyPath::identifier($uriVariables, 'productId'));
        } catch (ProductNotInCompany $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return array_map(static fn (array $row): ProductReorderPointResource => ProductReorderPointResource::of(...$row), $points);
    }
}

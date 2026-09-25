<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Inventory\Application\KeepReorderPoints;
use App\Module\Inventory\Application\ProductNotInCompany;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Takes a product's reorder point away in one establishment; one it did not have there answers 204 all the same.
 *
 * @implements ProcessorInterface<ProductReorderPointResource, null>
 */
final readonly class ClearProductReorderPointProcessor implements ProcessorInterface
{
    public function __construct(private KeepReorderPoints $points, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);

        try {
            $this->points->clear(
                $company,
                CompanyPath::identifier($uriVariables, 'productId'),
                CompanyPath::identifier($uriVariables, 'establishmentId'),
                $this->guard->account()->getId(),
            );
        } catch (ProductNotInCompany $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return null;
    }
}

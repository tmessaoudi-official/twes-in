<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Inventory\Application\KeepProductHomes;
use App\Module\Inventory\Application\ProductNotInCompany;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Takes a product's home away in one establishment. A product that had none there answers 204 all the same: that is
 * what pressing the same button twice means, and a 404 would be about a row rather than about the product.
 *
 * @implements ProcessorInterface<ProductHomeResource, null>
 */
final readonly class ClearProductHomeProcessor implements ProcessorInterface
{
    public function __construct(private KeepProductHomes $homes, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);

        try {
            $this->homes->clear(
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

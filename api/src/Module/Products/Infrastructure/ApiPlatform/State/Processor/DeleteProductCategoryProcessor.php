<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Products\Application\Category\ManageProductCategories;
use App\Module\Products\Application\Category\ProductCategoryInUse;
use App\Module\Products\Application\Category\ProductCategoryNotFound;
use App\Module\Products\Infrastructure\ApiPlatform\Resource\ProductCategoryResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<ProductCategoryResource, null> */
final readonly class DeleteProductCategoryProcessor implements ProcessorInterface
{
    public function __construct(private ManageProductCategories $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);

        try {
            $this->manage->delete($company, CompanyPath::identifier($uriVariables, 'categoryId'), $this->guard->account()->getId());
        } catch (ProductCategoryNotFound $absent) {
            throw new NotFoundHttpException('No such product category.', $absent);
        } catch (ProductCategoryInUse $inUse) {
            throw new ConflictHttpException($inUse->getMessage(), $inUse);
        }

        return null;
    }
}

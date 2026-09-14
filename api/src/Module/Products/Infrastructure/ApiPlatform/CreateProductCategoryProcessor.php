<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Products\Application\ManageProductCategories;
use App\Module\Products\Application\ProductCategoryNameTaken;
use App\Module\Products\Domain\InvalidProductCategory;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<ProductCategoryResource, ProductCategoryResource> */
final readonly class CreateProductCategoryProcessor implements ProcessorInterface
{
    public function __construct(private ManageProductCategories $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductCategoryResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);

        try {
            $category = $this->manage->create($company, $data->name, null === $data->parentId ? null : Uuid::fromString($data->parentId), $this->guard->account()->getId());
        } catch (ProductCategoryNameTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidProductCategory $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return ProductCategoryResource::of($category, 0, 0);
    }
}

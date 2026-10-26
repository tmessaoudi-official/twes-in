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
use App\Module\Products\Application\Category\ProductCategoryNameTaken;
use App\Module\Products\Application\Category\ProductCategoryNotFound;
use App\Module\Products\Domain\Exception\InvalidProductCategory;
use App\Module\Products\Infrastructure\ApiPlatform\Resource\ProductCategoryResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<ProductCategoryResource, ProductCategoryResource> */
final readonly class ReviseProductCategoryProcessor implements ProcessorInterface
{
    public function __construct(private ManageProductCategories $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductCategoryResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);

        try {
            $category = $this->manage->revise($company, CompanyPath::identifier($uriVariables, 'categoryId'), $data->name, null === $data->parentId ? null : Uuid::fromString($data->parentId), $this->guard->account()->getId(), $data->version);
        } catch (ProductCategoryNotFound $absent) {
            throw new NotFoundHttpException('No such product category.', $absent);
        } catch (ProductCategoryNameTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidProductCategory $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return ProductCategoryResource::of($category, $this->manage->productCount($category), $this->manage->childCount($category));
    }
}

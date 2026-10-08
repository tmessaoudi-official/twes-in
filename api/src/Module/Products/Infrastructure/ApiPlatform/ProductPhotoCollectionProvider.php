<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Application\ProductNotFound;
use App\Module\Products\Application\ProductPhotos;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<ProductPhotoResource> */
final readonly class ProductPhotoCollectionProvider implements ProviderInterface
{
    public function __construct(private ProductPhotos $photos, private CompanyGuard $guard)
    {
    }

    /** @return list<ProductPhotoResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);
        try {
            return array_map(ProductPhotoResource::of(...), $this->photos->of($company, CompanyPath::identifier($uriVariables, 'productId')));
        } catch (ProductNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }
    }
}

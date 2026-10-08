<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Products\Application\ProductNotFound;
use App\Module\Products\Application\ProductPhotoNotFound;
use App\Module\Products\Application\ProductPhotos;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<ProductPhotoResource, null> */
final readonly class RemoveProductPhotoProcessor implements ProcessorInterface
{
    public function __construct(private ProductPhotos $photos, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);
        try {
            $this->photos->remove($company, CompanyPath::identifier($uriVariables, 'productId'), CompanyPath::identifier($uriVariables, 'photoId'), $this->guard->account()->getId());
        } catch (ProductNotFound|ProductPhotoNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return null;
    }
}

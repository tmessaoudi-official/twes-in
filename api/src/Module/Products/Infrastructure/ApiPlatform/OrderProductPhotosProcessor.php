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
use App\Module\Products\Application\ProductPhotos;
use App\Module\Products\Application\ProductPhotosChanged;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<ProductPhotoOrder, null> */
final readonly class OrderProductPhotosProcessor implements ProcessorInterface
{
    public function __construct(private ProductPhotos $photos, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);
        try {
            $this->photos->order($company, CompanyPath::identifier($uriVariables, 'productId'), $data->ids(), $this->guard->account()->getId());
        } catch (ProductNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (ProductPhotosChanged $changed) {
            throw new ConflictHttpException($changed->getMessage(), $changed);
        }

        return null;
    }
}

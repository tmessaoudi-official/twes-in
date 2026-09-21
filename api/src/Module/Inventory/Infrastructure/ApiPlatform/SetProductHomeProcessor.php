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
use App\Module\Inventory\Domain\InvalidStockLocation;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Gives a product a home. A PUT with no establishment in the path because the LOCATION says which establishment
 * this is the home for, and a product already having one there has it moved rather than doubled.
 *
 * @implements ProcessorInterface<ProductHomeResource, ProductHomeResource>
 */
final readonly class SetProductHomeProcessor implements ProcessorInterface
{
    public function __construct(private KeepProductHomes $homes, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductHomeResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);

        try {
            $home = $this->homes->set(
                $company,
                CompanyPath::identifier($uriVariables, 'productId'),
                Uuid::fromString($data->locationId),
                $this->guard->account()->getId(),
            );
        } catch (ProductNotInCompany $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (InvalidStockLocation $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return ProductHomeResource::of($home);
    }
}

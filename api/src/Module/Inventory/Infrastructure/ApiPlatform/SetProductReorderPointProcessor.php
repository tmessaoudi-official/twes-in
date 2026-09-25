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
use App\Module\Inventory\Domain\InvalidReorderPoint;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Sets a product's reorder point in the establishment the path names, changing the one it already had there.
 *
 * @implements ProcessorInterface<ProductReorderPointResource, ProductReorderPointResource>
 */
final readonly class SetProductReorderPointProcessor implements ProcessorInterface
{
    public function __construct(private KeepReorderPoints $points, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductReorderPointResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);

        try {
            $point = $this->points->set(
                $company,
                CompanyPath::identifier($uriVariables, 'productId'),
                CompanyPath::identifier($uriVariables, 'establishmentId'),
                (string) $data->quantity,
                $this->guard->account()->getId(),
            );
        } catch (ProductNotInCompany $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (InvalidReorderPoint $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return ProductReorderPointResource::of($point->getEstablishment(), $point);
    }
}

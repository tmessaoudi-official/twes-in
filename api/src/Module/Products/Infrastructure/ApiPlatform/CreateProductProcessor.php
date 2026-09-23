<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Application\ProductBarcodeTaken;
use App\Module\Products\Application\ProductReferenceTaken;
use App\Module\Products\Domain\InvalidProduct;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<ProductResource, ProductResource> */
final readonly class CreateProductProcessor implements ProcessorInterface
{
    public function __construct(private ManageProducts $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);

        try {
            $seesCosts = $this->guard->may($company, ProductPermission::COST_READ);
            $product = $this->manage->create($company, $data->input($seesCosts), $this->guard->account()->getId());
        } catch (ProductReferenceTaken|ProductBarcodeTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidProduct $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return ProductResource::of($product, $seesCosts);
    }
}

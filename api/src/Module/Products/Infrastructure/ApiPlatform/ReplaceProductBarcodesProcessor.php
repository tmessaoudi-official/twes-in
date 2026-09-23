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
use App\Module\Products\Application\ProductNotFound;
use App\Module\Products\Domain\InvalidProduct;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<ProductBarcodesResource, ProductBarcodesResource> */
final readonly class ReplaceProductBarcodesProcessor implements ProcessorInterface
{
    public function __construct(private ManageProducts $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductBarcodesResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);

        try {
            $seesCosts = $this->guard->may($company, ProductPermission::COST_READ);
            $product = $this->manage->replaceBarcodes($company, CompanyPath::identifier($uriVariables, 'productId'), $data->input(), $this->guard->account()->getId(), $seesCosts);
        } catch (ProductNotFound $absent) {
            throw new NotFoundHttpException('No such product.', $absent);
        } catch (ProductBarcodeTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidProduct $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return ProductBarcodesResource::of($product, $seesCosts);
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\ReadReceiptCost;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<ReceiptCostResource> */
final readonly class ReceiptCostProvider implements ProviderInterface
{
    public function __construct(
        private CompanyGuard $guard,
        private ProductRepository $products,
        private ReadReceiptCost $read,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReceiptCostResource
    {
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');
        $this->guard->companyForActing($companyId, StockPermission::READ);
        $company = $this->guard->companyForActing($companyId, ProductPermission::COST_READ);

        $productId = Paging::identifier($operation, 'productId');
        $product = null === $productId ? null : $this->products->ofIdInCompany($productId, $company->getId());
        if (null === $product) {
            throw new NotFoundHttpException('No such product.');
        }

        return ReceiptCostResource::of($this->read->read($company, $product, Paging::text($operation, 'quantity'), Paging::text($operation, 'unitCost')));
    }
}

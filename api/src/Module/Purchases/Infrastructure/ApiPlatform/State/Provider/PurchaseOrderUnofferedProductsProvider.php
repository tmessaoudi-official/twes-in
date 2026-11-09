<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Purchases\Application\OrderProducts;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Shared\Infrastructure\ApiPlatform\Output\UnofferedProducts;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * What the order's product picker leaves out of what the words find, read with the order's permission as the picker
 * is (`PurchaseOrderProductPickProvider`): the goods switched off, and the services, which an order never offers.
 *
 * @implements ProviderInterface<UnofferedProducts>
 */
final readonly class PurchaseOrderUnofferedProductsProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private OrderProducts $products)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UnofferedProducts
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), PurchaseOrderPermission::READ);

        return UnofferedProducts::of($this->products->unoffered($company, Paging::text($operation) ?? ''));
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Products\Application\PickProducts;
use App\Shared\Infrastructure\ApiPlatform\Output\UnofferedProducts;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * What the invoice's product picker leaves out of what the words find, read with the invoice's permission as the
 * picker is (`InvoiceProductPickProvider`): the products switched off.
 *
 * @implements ProviderInterface<UnofferedProducts>
 */
final readonly class InvoiceUnofferedProductsProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private PickProducts $products)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UnofferedProducts
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);

        return UnofferedProducts::of($this->products->unoffered($company, Paging::text($operation) ?? ''));
    }
}

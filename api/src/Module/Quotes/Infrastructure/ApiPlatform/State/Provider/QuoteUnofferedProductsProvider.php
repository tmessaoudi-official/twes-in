<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Module\Quotes\Application\QuoteProducts;
use App\Module\Quotes\Infrastructure\ApiPlatform\Security\QuotePermission;
use App\ModuleRegistry\Application\ModuleStates;
use App\Shared\Infrastructure\ApiPlatform\Output\UnofferedProducts;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * What the quote's product picker leaves out of what the words find, read with the quote's permission as the picker
 * is (`QuoteProductPickProvider`): the products switched off. With the products module off the picker offers none and
 * hides none.
 *
 * @implements ProviderInterface<UnofferedProducts>
 */
final readonly class QuoteUnofferedProductsProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private QuoteProducts $products, private ModuleStates $modules)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UnofferedProducts
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::READ);
        if (!$this->modules->isEnabled($company->getId(), ProductsModule::KEY)) {
            return new UnofferedProducts();
        }

        return UnofferedProducts::of($this->products->unoffered($company, Paging::text($operation) ?? ''));
    }
}

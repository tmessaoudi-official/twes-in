<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Module\Quotes\Application\QuoteProducts;
use App\ModuleRegistry\Application\ModuleStates;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * A few products for the quote form's picker, under the quote's own permission (see the resource beside this).
 *
 * @implements ProviderInterface<QuoteProductPickResource>
 */
final readonly class QuoteProductPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private QuoteProducts $products, private ModuleStates $modules)
    {
    }

    /** @return list<QuoteProductPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::READ);
        if (!$this->modules->isEnabled($company->getId(), ProductsModule::KEY)) {
            return [];
        }

        $ids = Paging::uuids($operation, 'ids');

        return array_map(QuoteProductPickResource::of(...), [] === $ids
            ? $this->products->matching($company, Paging::text($operation) ?? '')
            : $this->products->byIds($company, $ids));
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Application\PickProducts;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * A few products for the invoice form's picker, under the invoice's own permission (see the resource beside this).
 *
 * @implements ProviderInterface<InvoiceProductPickResource>
 */
final readonly class InvoiceProductPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private PickProducts $products)
    {
    }

    /** @return list<InvoiceProductPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);

        return array_map(InvoiceProductPickResource::of(...), $this->products->matching($company, Paging::text($operation) ?? ''));
    }
}

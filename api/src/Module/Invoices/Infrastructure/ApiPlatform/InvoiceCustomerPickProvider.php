<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Customers\Application\PickCustomers;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * A few customers for the invoice form's picker, under the invoice's own permission (see the resource beside this).
 *
 * @implements ProviderInterface<InvoiceCustomerPickResource>
 */
final readonly class InvoiceCustomerPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private PickCustomers $customers)
    {
    }

    /** @return list<InvoiceCustomerPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);

        return array_map(InvoiceCustomerPickResource::of(...), $this->customers->matching($company, Paging::text($operation) ?? ''));
    }
}

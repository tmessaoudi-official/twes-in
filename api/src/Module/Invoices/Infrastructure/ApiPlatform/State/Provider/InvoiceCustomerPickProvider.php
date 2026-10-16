<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Customers\Application\PickCustomers;
use App\Module\Invoices\Infrastructure\ApiPlatform\Resource\InvoiceCustomerPickResource;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
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

        $ids = Paging::uuids($operation, 'ids');

        return array_map(InvoiceCustomerPickResource::of(...), [] === $ids
            ? $this->customers->matching($company, Paging::text($operation) ?? '')
            : $this->customers->byIds($company, $ids));
    }
}

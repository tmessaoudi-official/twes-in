<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Infrastructure\ApiPlatform\Payload\InvoiceSearchReader;
use App\Module\Invoices\Infrastructure\ApiPlatform\Resource\InvoiceStatusCountsResource;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProviderInterface<InvoiceStatusCountsResource> */
final readonly class InvoiceStatusCountsProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ManageInvoices $manage)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InvoiceStatusCountsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);

        return InvoiceStatusCountsResource::of($this->manage->statusCounts($company, InvoiceSearchReader::read(Paging::parameters($context), Paging::text($operation), [], $company, withStatus: false)));
    }
}

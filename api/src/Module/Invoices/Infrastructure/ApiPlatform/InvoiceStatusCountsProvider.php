<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\InvoiceSearch;
use App\Module\Invoices\Domain\InvoiceType;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<InvoiceStatusCountsResource> */
final readonly class InvoiceStatusCountsProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ManageInvoices $manage)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InvoiceStatusCountsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);
        $documentType = Paging::value($operation, 'documentType');
        $customer = Paging::value($operation, 'customerId');

        return InvoiceStatusCountsResource::of($this->manage->statusCounts($company, new InvoiceSearch(
            Paging::text($operation),
            null,
            \is_string($documentType) ? InvoiceType::from($documentType) : null,
            \is_string($customer) && Uuid::isValid($customer) ? Uuid::fromString($customer) : null,
        )));
    }
}

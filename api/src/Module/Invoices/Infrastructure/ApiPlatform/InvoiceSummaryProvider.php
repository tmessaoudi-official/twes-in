<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Invoices\Application\SummarizeInvoices;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<InvoiceSummaryResource> */
final readonly class InvoiceSummaryProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private SummarizeInvoices $summarize)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InvoiceSummaryResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);

        return InvoiceSummaryResource::of($this->summarize->handle($company));
    }
}

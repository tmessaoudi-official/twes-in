<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\Invoice;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<InvoiceResource> */
final readonly class InvoiceCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageInvoices $manage, private InvoiceTotals $totals, private CompanyGuard $guard)
    {
    }

    /** @return list<InvoiceResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);

        return array_map(fn (Invoice $invoice) => InvoiceResource::of($invoice, $this->totals->figures($invoice)), $this->manage->list($company));
    }
}

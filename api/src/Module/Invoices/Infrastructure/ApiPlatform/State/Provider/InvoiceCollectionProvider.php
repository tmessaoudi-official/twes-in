<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\ValueObject\InvoiceSearch;
use App\Module\Invoices\Infrastructure\ApiPlatform\Payload\InvoiceSearchReader;
use App\Module\Invoices\Infrastructure\ApiPlatform\Resource\InvoiceResource;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * One page of a company's invoices and credit notes, searched, narrowed and sorted in the database (docs/SPEC.md § 7,
 * lists at scale). Each row's figures are read for the page alone, so what it costs does not grow with the ledger.
 *
 * @implements ProviderInterface<InvoiceResource>
 */
final readonly class InvoiceCollectionProvider implements ProviderInterface
{
    public function __construct(
        private ManageInvoices $manage,
        private InvoiceTotals $totals,
        private CompanyGuard $guard,
        private Paging $paging,
    ) {
    }

    /** @return TraversablePaginator<InvoiceResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);
        $search = InvoiceSearchReader::read(Paging::parameters($context), Paging::text($operation), Paging::order($operation, InvoiceSearch::SORTS), $company);

        $withCosts = $this->guard->may($company, ProductPermission::COST_READ);

        return $this->paging->paginator(
            $this->manage->search($company, $search, $this->paging->request($operation, $context)),
            fn (Invoice $invoice): InvoiceResource => InvoiceResource::of($invoice, $this->totals->figures($invoice), $withCosts),
        );
    }
}

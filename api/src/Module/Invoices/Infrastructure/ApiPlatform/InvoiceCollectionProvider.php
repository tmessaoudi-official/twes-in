<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceSearch;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceType;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\Uid\Uuid;

/**
 * One page of a company's invoices and credit notes, searched, narrowed and sorted in the database (docs/SPEC.md § 7,
 * lists at scale). Each row's figures are read for the page alone, so what it costs does not grow with the ledger.
 *
 * @implements ProviderInterface<InvoiceResource>
 */
final readonly class InvoiceCollectionProvider implements ProviderInterface
{
    /** What the status column shows for an invoice past its due day, which is not a status the document holds. */
    private const string OVERDUE = 'overdue';

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
        $status = Paging::value($operation, 'status');
        $documentType = Paging::value($operation, 'documentType');
        $customer = Paging::value($operation, 'customerId');
        // Overdue is asked for as a status because that is how the column reads, and answered against the company's
        // own day rather than the server's.
        $overdue = self::OVERDUE === $status;
        $search = new InvoiceSearch(
            Paging::text($operation),
            \is_string($status) && !$overdue ? InvoiceStatus::from($status) : null,
            \is_string($documentType) ? InvoiceType::from($documentType) : null,
            \is_string($customer) && Uuid::isValid($customer) ? Uuid::fromString($customer) : null,
            Paging::order($operation, InvoiceSearch::SORTS),
            $overdue ? new \DateTimeImmutable('today', new \DateTimeZone($company->getTimezone())) : null,
        );

        return $this->paging->paginator(
            $this->manage->search($company, $search, $this->paging->request($operation, $context)),
            fn (Invoice $invoice): InvoiceResource => InvoiceResource::of($invoice, $this->totals->figures($invoice)),
        );
    }
}

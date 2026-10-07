<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Export;

use App\ImportExport\Application\DeclaresExport;
use App\ImportExport\Application\ExportQuery;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceKind;
use App\Module\Invoices\Domain\InvoiceSearch;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoicePermission;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoiceSearchReader;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;

/**
 * The invoices and credit notes list as a file (docs/SPEC.md § 7, row 60): one row per document, under the search,
 * status, kind, customer and order the screen shows; a deposit is named `deposit` in the type column, so the file
 * tells it from a final invoice. Days go out as 2026-09-15, amounts as the decimals the document
 * holds, so a spreadsheet sums and sorts them without guessing a locale.
 */
final readonly class InvoiceExport implements DeclaresExport
{
    private const int BATCH = 200;
    private const string KEY = 'invoices';

    public function __construct(private ManageInvoices $manage, private InvoiceTotals $totals)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function permission(): string
    {
        return InvoicePermission::READ;
    }

    public function module(): string
    {
        return InvoicesModule::KEY;
    }

    public function columns(Company $company): array
    {
        return ['number', 'type', 'status', 'customer_number', 'customer', 'issue_date', 'due_date', 'currency', 'total_net', 'total_tax', 'total', 'amount_paid', 'amount_credited', 'amount_due', 'customer_reference'];
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        $search = InvoiceSearchReader::read($query->parameters(), $query->text(), $query->order(InvoiceSearch::SORTS), $company);

        for ($page = 1;; ++$page) {
            $answer = $this->manage->search($company, $search, new PageRequest($page, self::BATCH));
            foreach ($answer->items as $invoice) {
                yield $this->row($invoice, $company);
            }
            if ($page * self::BATCH >= $answer->total || [] === $answer->items) {
                return;
            }
        }
    }

    /** @return list<string> */
    private function row(Invoice $invoice, Company $company): array
    {
        $figures = $this->totals->figures($invoice);
        $snapshot = $invoice->getCustomerSnapshot();
        $customer = $invoice->getCustomer();

        return [
            $invoice->getNumber() ?? '',
            InvoiceKind::of($invoice)->value,
            $invoice->getStatus()->value,
            $snapshot->number ?? $customer->getNumber(),
            $snapshot->name ?? $customer->getProfile()->name,
            $invoice->getIssueDate()?->format('Y-m-d') ?? '',
            $invoice->getDueDate()?->format('Y-m-d') ?? '',
            $company->getCurrency(),
            $figures->totalNet,
            $figures->totalTax,
            $figures->total,
            $figures->amountPaid,
            $figures->amountCredited,
            $figures->amountDue,
            $invoice->getHeader()->customerReference ?? '',
        ];
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Infrastructure\Export;

use App\ImportExport\Application\DeclaresExport;
use App\ImportExport\Application\ExportQuery;
use App\Module\AccountingExport\Application\SalesDocuments;
use App\Module\AccountingExport\Infrastructure\Module\AccountingExportModule;
use App\Module\AccountingExport\Infrastructure\Module\AccountingExportPermission;
use App\Tenancy\Domain\Company;

/**
 * The sales journal of a period: one row per tax each issued invoice and credit note carries, its base and amount, with
 * the document's net and total repeated on each, a credit note negative. A document with no tax is one row with none.
 * Days go out as 2026-09-15 and amounts as decimals, so an accountant's software reads them without guessing a locale.
 */
final readonly class SalesJournalExport implements DeclaresExport
{
    public function __construct(private SalesDocuments $documents)
    {
    }

    public function key(): string
    {
        return 'sales-journal';
    }

    public function permission(): string
    {
        return AccountingExportPermission::EXPORT;
    }

    public function module(): string
    {
        return AccountingExportModule::KEY;
    }

    public function columns(Company $company): array
    {
        return ['date', 'number', 'type', 'customer_number', 'customer', 'customer_identifiers', 'tax_code', 'tax_rate', 'base', 'tax', 'document_net', 'document_total', 'currency'];
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        foreach ($this->documents->issuedIn($company, PeriodAsked::of($query)) as $document) {
            $head = [$document->issueDate->format('Y-m-d'), $document->number, $document->type, $document->customerNumber, $document->customerName, $document->customerIdentifiers];
            $tail = [$document->totalNet, $document->total, $company->getCurrency()];
            if ([] === $document->taxes) {
                yield [...$head, '', '', $document->totalNet, '0', ...$tail];
                continue;
            }
            foreach ($document->taxes as $tax) {
                yield [...$head, $tax->code, $tax->rate ?? '', $tax->base ?? '', $tax->amount, ...$tail];
            }
        }
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Infrastructure\Export;

use App\ImportExport\Application\DeclaresExport;
use App\ImportExport\Application\ExportQuery;
use App\Module\AccountingExport\Application\PurchaseEntries;
use App\Module\AccountingExport\Infrastructure\Module\AccountingExportModule;
use App\Module\AccountingExport\Infrastructure\Module\AccountingExportPermission;
use App\Tenancy\Domain\Company;

/** The purchases journal of a period: one row per expense entered in the books, on its own day; a draft is not in them. */
final readonly class PurchasesJournalExport implements DeclaresExport
{
    public function __construct(private PurchaseEntries $purchases)
    {
    }

    public function key(): string
    {
        return 'purchases-journal';
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
        return ['date', 'reference', 'vendor', 'description', 'category', 'tax_code', 'tax_rate', 'net', 'tax', 'gross', 'withholding', 'status', 'paid_on', 'payment_method', 'currency'];
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        foreach ($this->purchases->enteredIn($company, PeriodAsked::of($query)) as $entry) {
            yield [$entry->date->format('Y-m-d'), $entry->reference, $entry->vendor, $entry->description, $entry->category, $entry->taxCode, $entry->taxRate ?? '', $entry->net, $entry->tax, $entry->gross, $entry->withholding, $entry->status, $entry->paidOn?->format('Y-m-d') ?? '', $entry->paymentMethod, $company->getCurrency()];
        }
    }
}

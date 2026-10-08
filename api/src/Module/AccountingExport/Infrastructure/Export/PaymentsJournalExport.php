<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Infrastructure\Export;

use App\ImportExport\Application\DeclaresExport;
use App\ImportExport\Application\ExportQuery;
use App\Module\AccountingExport\Application\PaymentEntries;
use App\Module\AccountingExport\Infrastructure\Module\AccountingExportModule;
use App\Module\AccountingExport\Infrastructure\Module\AccountingExportPermission;
use App\Tenancy\Domain\Company;

/** The payments journal of a period: one row per payment received on an invoice, on the day it was received. */
final readonly class PaymentsJournalExport implements DeclaresExport
{
    public function __construct(private PaymentEntries $payments)
    {
    }

    public function key(): string
    {
        return 'payments-journal';
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
        return ['date', 'invoice_number', 'customer_number', 'customer', 'method', 'reference', 'amount', 'currency'];
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        foreach ($this->payments->receivedIn($company, PeriodAsked::of($query)) as $payment) {
            yield [$payment->date->format('Y-m-d'), $payment->invoiceNumber, $payment->customerNumber, $payment->customerName, $payment->method, $payment->reference, $payment->amount, $company->getCurrency()];
        }
    }
}

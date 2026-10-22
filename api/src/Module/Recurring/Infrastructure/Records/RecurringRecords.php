<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\Records;

use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Recurring\Infrastructure\Module\RecurringModule;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;

/**
 * A recurring invoice is a schedule, deleted freely and put back within the day; the drafts it made stay, naming it in
 * their history alone. It holds no word of its own, so it reads as its model and the customer the model bills.
 */
final readonly class RecurringRecords implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [new RecordSubject('recurring_invoice', InvoicePermission::WRITE, RecurringModule::KEY, [], labelTable: 'invoice', labelKey: 'model_invoice_id', labelTableColumns: ['number'], labelNextTable: 'customer', labelNextKey: 'customer_id', labelNextColumns: ['name'])];
    }

    public function references(): array
    {
        // An `invoice_id` names an invoice: only its name reads like a recurring invoice's.
        return [
            RecordReference::ignored('customer_credit_entry', 'invoice_id', 'recurring_invoice', 'A credit entry names the invoice it was spent on, never a schedule.'),
            RecordReference::ignored('invoice_reminder', 'invoice_id', 'recurring_invoice', 'A reminder names the invoice it was sent for, never a schedule.'),
            RecordReference::ignored('quote', 'invoice_id', 'recurring_invoice', 'A quote names the invoice it became, never a schedule.'),
        ];
    }
}

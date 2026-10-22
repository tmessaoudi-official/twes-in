<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Records;

use App\Module\Invoices\Domain\ValueObject\InstrumentStatus;
use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;

/**
 * An invoice names the customer it bills and its lines the products they sell: what an invoice names is used, and an
 * issued one keeps naming it through a merge. A customer's credit is its own record of what it was owed and spent.
 */
final readonly class InvoiceRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        // Only a draft is deleted: what was issued is the books', corrected by a credit note. A draft has no number yet,
        // so it reads as the customer it bills.
        return [
            new RecordSubject('invoice', InvoicePermission::WRITE, InvoicesModule::KEY, ['number'], whereColumn: 'status', whereValues: [InvoiceStatus::Draft->value], labelTable: 'customer', labelKey: 'customer_id', labelTableColumns: ['number', 'name']),
            // A cheque or a draft still held, entered by mistake, is taken out of the portfolio; one deposited, cashed or
            // come back unpaid stays as the record of what happened. It reads as its number and bank, then its invoice's.
            new RecordSubject('payment_instrument', InvoicePermission::PAYMENT_WRITE, InvoicesModule::KEY, ['number', 'bank'], whereColumn: 'status', whereValues: [InstrumentStatus::Held->value], labelTable: 'invoice', labelKey: 'invoice_id', labelTableColumns: ['number']),
        ];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('invoice', 'corrects_invoice_id', 'invoice', RecordMerge::Ignored, 'A credit note names the invoice it corrects, an issued one; one naming a draft keeps it.'),
            RecordReference::uses('invoice_line', 'deducts_invoice_id', 'invoice', RecordMerge::Ignored, 'A line deducting a deposit names the deposit invoice, an issued one; one naming a draft keeps it.'),
            RecordReference::uses('invoice_line', 'credited_line_id', 'invoice', RecordMerge::Ignored, 'A credit note line names the line it credits, an issued invoice\'s; one naming a draft\'s keeps it.')
                ->below('invoice_line', 'invoice_id'),
            RecordReference::uses('invoice_line', 'source_delivery_note_line_id', 'delivery_note', RecordMerge::Ignored, 'An invoice line names the delivery note line it invoices; a draft note so invoiced is kept.')
                ->below('delivery_note_line', 'delivery_note_id'),
            RecordReference::uses('customer_credit_entry', 'invoice_id', 'invoice', RecordMerge::Ignored, 'A credit entry names the invoice it was spent on or came from; a draft so named is kept.'),
            RecordReference::uses('invoice', 'quote_id', 'quote', RecordMerge::Ignored, 'An invoice names the quote it was made from; that quote is kept.'),
            RecordReference::uses('invoice_reminder', 'invoice_id', 'invoice', RecordMerge::Ignored, 'A reminder chases an issued invoice; one naming a draft keeps it.'),
            RecordReference::uses('invoice_reminder', 'late_fee_invoice_id', 'invoice', RecordMerge::Ignored, 'A reminder names the late-fee invoice it made; that draft is kept, cancelled rather than deleted.'),
            RecordReference::uses('recurring_invoice', 'model_invoice_id', 'invoice', RecordMerge::Ignored, 'A recurring invoice copies its model; the model is kept while it does.'),
            RecordReference::uses('recurring_invoice', 'last_invoice_id', 'invoice', RecordMerge::Ignored, 'A recurring invoice names the last invoice it made, from which it counts the next; that one is kept.'),
            RecordReference::owned('invoice_line', 'invoice_id', 'invoice', RecordMerge::Ignored, 'A line is part of its draft: it goes with it and comes back with it.'),
            RecordReference::owned('invoice_line_tax', 'line_id', 'invoice', RecordMerge::Ignored, 'A line\'s tax is part of its line: it goes before it and comes back after it.')
                ->below('invoice_line', 'invoice_id'),
            RecordReference::owned('invoice_tax', 'invoice_id', 'invoice', RecordMerge::Ignored, 'A document tax (the stamp) is part of its draft.'),
            RecordReference::uses('payment', 'invoice_id', 'invoice', RecordMerge::Ignored, 'A payment settles an issued invoice; one naming a draft keeps it.'),
            RecordReference::uses('payment_instrument', 'invoice_id', 'invoice', RecordMerge::Ignored, 'A cheque or a draft held names the invoice it pays; one naming a draft keeps it.'),
            RecordReference::uses('invoice_line', 'product_id', 'product', RecordMerge::Moves, 'An invoice line names the product it sells; merged, a draft sells the product kept, an issued invoice what it printed.')
                ->through('invoice', 'invoice_id')
                ->movingOnlyWhere('status', [InvoiceStatus::Draft->value])
                ->transactingAt('issued_at'),
            // Any invoice of the customer kept written since but a draft, issued, paid or cancelled, means it traded.
            RecordReference::uses('invoice', 'customer_id', 'customer', RecordMerge::Moves, 'An invoice bills its customer; merged, a draft bills the customer kept, an issued invoice whom it printed.')
                ->movingOnlyWhere('status', [InvoiceStatus::Draft->value])
                ->transactingAt('updated_at'),
            RecordReference::uses('customer_credit_entry', 'customer_id', 'customer', RecordMerge::Stays, 'A credit entry is what the customer was owed or spent, as recorded; merged, the customer kept stands for it.')
                ->transactingAt('created_at'),
        ];
    }
}

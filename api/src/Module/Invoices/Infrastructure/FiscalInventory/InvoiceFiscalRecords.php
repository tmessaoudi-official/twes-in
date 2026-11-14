<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Fiscal\Application\Inventory\FiscalRecords;

/**
 * The invoices, deposit invoices and credit notes issued (every state past a draft, and any numbered one), with their
 * lines, taxes and stored PDFs; the hash chains' heads; and what settled them: payments, cheques and drafts held, a
 * customer's credit.
 */
final readonly class InvoiceFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [
            FiscalRecords::fiscal('invoices.issued', 'invoice', "status <> 'draft' OR number IS NOT NULL", ['invoice_line', 'invoice_line_tax', 'invoice_tax'], pdfColumn: 'pdf_file_id', numberColumn: 'number', dateColumn: 'issue_date'),
            FiscalRecords::fiscal('invoices.chain_heads', 'invoice_chain_head'),
            FiscalRecords::fiscal('invoices.payments', 'payment', dateColumn: 'payment_date'),
            FiscalRecords::fiscal('invoices.payment_instruments', 'payment_instrument', dateColumn: 'due_on'),
            FiscalRecords::fiscal('invoices.credit_entries', 'customer_credit_entry', dateColumn: 'entry_date'),
        ];
    }

    public function notFiscal(): array
    {
        return [
            'invoice_reminder' => 'a reminder sent about an invoice, counted with it; a late fee it charged is an invoice of its own',
        ];
    }
}

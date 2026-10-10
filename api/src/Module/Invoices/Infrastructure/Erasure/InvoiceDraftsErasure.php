<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Erasure;

use App\Erasure\Application\DeclaresErasure;
use App\Erasure\Application\ErasedRows;
use App\Erasure\Application\ErasureReference;

/**
 * « Brouillons et devis », the invoices' share: every draft goes, with all it holds, but a draft another record still
 * needs (what a recurring invoice copies, a reminder's late fee). An issued invoice is never a draft, and never goes.
 */
final readonly class InvoiceDraftsErasure implements DeclaresErasure
{
    public function steps(): array
    {
        $drafts = ErasedRows::of('drafts', 'invoice', "status = 'draft'", 'drafts', 'invoice');
        $lines = $drafts->child('invoice_line', 'invoice_id');

        return [
            $drafts,
            $lines,
            $lines->child('invoice_line_tax', 'line_id'),
            $drafts->child('invoice_tax', 'invoice_id'),
            // A draft has no payment; were one there, its cascade must not take it uncopied.
            $drafts->child('payment', 'invoice_id', live: 'payment'),
            $drafts->child('payment_instrument', 'invoice_id'),
        ];
    }

    public function references(): array
    {
        return [
            ErasureReference::keep('invoice_reminder', 'late_fee_invoice_id', 'invoice', 'The late fee a reminder raised stays while the reminder names it.'),
            ErasureReference::ignore('invoice_reminder', 'invoice_id', 'invoice', 'Only an issued invoice is reminded.'),
            ErasureReference::keep('invoice', 'quote_id', 'quote', 'A quote stays while an invoice that stays says it came from it.'),
            ErasureReference::ignore('invoice', 'corrects_invoice_id', 'invoice', 'Only an issued invoice is corrected; a draft credit note goes as any draft.'),
            ErasureReference::ignore('invoice_line', 'deducts_invoice_id', 'invoice', 'A line deducts only an issued deposit invoice.'),
            ErasureReference::ignore('invoice_line', 'source_delivery_note_line_id', 'delivery_note_line', 'A line comes only from a validated delivery note, never a draft.'),
            ErasureReference::ignore('payment_instrument', 'payment_id', 'payment', 'An instrument goes with the draft it belongs to, as its payment does.'),
            ErasureReference::ignore('customer_credit_entry', 'invoice_id', 'invoice', 'A credit entry follows only an issued invoice or credit note.'),
            ErasureReference::ignore('customer_credit_entry', 'payment_id', 'payment', 'A credit entry follows only a payment of an issued invoice.'),
        ];
    }

    public function files(): array
    {
        return [];
    }
}

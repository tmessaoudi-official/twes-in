<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Records;

use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;

/**
 * An invoice names the customer it bills and its lines the products they sell: what an invoice names is used, and an
 * issued one keeps naming it through a merge. A customer's credit is its own record of what it was owed and spent.
 */
final readonly class InvoiceRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [];
    }

    public function references(): array
    {
        return [
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

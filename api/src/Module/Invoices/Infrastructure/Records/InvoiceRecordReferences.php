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

/** An invoice's line names the product it sells: a product an invoice names is used, and its line keeps naming it through a merge. */
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
        ];
    }
}

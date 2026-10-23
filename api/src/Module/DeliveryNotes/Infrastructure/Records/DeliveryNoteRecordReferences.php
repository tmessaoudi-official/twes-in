<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Records;

use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteStatus;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Security\DeliveryNotePermission;
use App\Module\DeliveryNotes\Infrastructure\Module\DeliveryNotesModule;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;

/** A delivery note names the customer it delivers and its lines the products: what a note names is used. */
final readonly class DeliveryNoteRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        // Only a draft is deleted: a validated note moved the stock and was handed over. A draft has no number yet, so it
        // reads as the customer it delivers.
        return [new RecordSubject('delivery_note', DeliveryNotePermission::WRITE, DeliveryNotesModule::KEY, ['number'], whereColumn: 'status', whereValues: [DeliveryNoteStatus::Draft->value], labelTable: 'customer', labelKey: 'customer_id', labelTableColumns: ['number', 'name'])];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('delivery_note', 'invoiced_by_invoice_id', 'invoice', RecordMerge::Ignored, 'A note names the invoice that invoiced it, an issued one; one naming a draft keeps it.'),
            RecordReference::owned('delivery_note_line', 'delivery_note_id', 'delivery_note', RecordMerge::Ignored, 'A line is part of its draft: it goes with it and comes back with it.'),
            RecordReference::uses('delivery_note_line', 'source_quote_line_id', 'quote', RecordMerge::Ignored, 'A delivery note line names the line of the accepted quote it delivers; a draft quote is never delivered.')
                ->below('quote_line', 'quote_id'),
            RecordReference::uses('delivery_note_line', 'source_invoice_line_id', 'invoice', RecordMerge::Ignored, 'A delivery note line names the line of the issued invoice whose goods it hands over; an issued invoice is never deleted.')
                ->below('invoice_line', 'invoice_id'),
            RecordReference::owned('delivery_note_line_tax', 'line_id', 'delivery_note', RecordMerge::Ignored, 'A line\'s tax is part of its line: it goes before it and comes back after it.')
                ->below('delivery_note_line', 'delivery_note_id'),
            RecordReference::uses('delivery_note_line', 'product_id', 'product', RecordMerge::Moves, 'A delivery note line names the product it delivers; merged, a draft delivers the product kept, a validated note what it printed.')
                ->through('delivery_note', 'delivery_note_id')
                ->movingOnlyWhere('status', [DeliveryNoteStatus::Draft->value]),
            RecordReference::uses('delivery_note', 'customer_id', 'customer', RecordMerge::Moves, 'A delivery note delivers its customer; merged, a draft delivers the customer kept, a validated note whom it printed.')
                ->movingOnlyWhere('status', [DeliveryNoteStatus::Draft->value])
                ->transactingAt('updated_at'),
        ];
    }
}

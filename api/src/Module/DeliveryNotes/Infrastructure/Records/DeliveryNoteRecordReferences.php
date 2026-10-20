<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Records;

use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteStatus;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;

/** A delivery note names the customer it delivers and its lines the products: what a note names is used. */
final readonly class DeliveryNoteRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('delivery_note_line', 'product_id', 'product', RecordMerge::Moves, 'A delivery note line names the product it delivers; merged, a draft delivers the product kept, a validated note what it printed.')
                ->through('delivery_note', 'delivery_note_id')
                ->movingOnlyWhere('status', [DeliveryNoteStatus::Draft->value]),
            RecordReference::uses('delivery_note', 'customer_id', 'customer', RecordMerge::Moves, 'A delivery note delivers its customer; merged, a draft delivers the customer kept, a validated note whom it printed.')
                ->movingOnlyWhere('status', [DeliveryNoteStatus::Draft->value])
                ->transactingAt('updated_at'),
        ];
    }
}

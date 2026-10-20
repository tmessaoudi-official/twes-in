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

/** A delivery note's line names the product it delivers: a product a note names is used. */
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
        ];
    }
}

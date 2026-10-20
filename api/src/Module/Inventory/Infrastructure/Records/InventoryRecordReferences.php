<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Records;

use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;

/**
 * A movement is never changed once written: it keeps the vendor it came from through a merge. Its foreign key empties
 * the column when the vendor goes, so a vendor a movement names is used, and a delete must refuse before it reaches it.
 */
final readonly class InventoryRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('stock_movement', 'vendor_id', 'vendor', RecordMerge::Stays, 'A movement names the vendor the goods came from.'),
            RecordReference::ignored('stock_movement', 'source_id', null, 'A movement\'s source is the document or count that made it, never a record a person deletes or merges.'),
        ];
    }
}

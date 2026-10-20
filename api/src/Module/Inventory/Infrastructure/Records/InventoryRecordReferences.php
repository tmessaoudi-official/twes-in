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
 * A product moved or kept in lots is used; where it is kept and when to reorder it belong to it.
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
            RecordReference::uses('stock_movement', 'product_id', 'product', RecordMerge::Stays, 'A movement names the product it moved.'),
            RecordReference::uses('stock_lot', 'product_id', 'product', RecordMerge::Stays, 'A lot is a batch of one product.'),
            RecordReference::owned('product_home_location', 'product_id', 'product', RecordMerge::MovesOrDrops, 'Where a product is kept is its own; merged, the product kept is kept there too, once.'),
            RecordReference::owned('product_reorder_point', 'product_id', 'product', RecordMerge::MovesOrDrops, 'A product\'s reorder points are its own; merged, the product kept keeps its own where it has one.'),
        ];
    }
}

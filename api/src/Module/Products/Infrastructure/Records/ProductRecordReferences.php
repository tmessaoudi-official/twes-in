<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Records;

use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;

/** What the products' own columns naming a vendor mean to deleting and merging it. */
final readonly class ProductRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('product_vendor', 'vendor_id', 'vendor', RecordMerge::MovesOrDrops, 'A product bought from the vendor names it; merged, the product is bought from the vendor kept, once.'),
            RecordReference::uses('product_barcode', 'supplier_id', 'vendor', RecordMerge::Moves, 'A vendor\'s own code for a product names it; merged, the code is the kept vendor\'s.'),
            RecordReference::ignored('product_cost_change', 'source_id', null, 'A cost changes from a receipt, a movement or a hand edit, never from a record a person deletes or merges.'),
        ];
    }
}

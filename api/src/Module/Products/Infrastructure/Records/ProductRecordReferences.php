<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Records;

use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;

/**
 * A product is deleted while nothing names it, restored, and merged into another; whoever writes products does it. Its
 * codes, vendors, photos and cost history belong to it: they go with it and come back with it. The products' own
 * columns naming a vendor are said here too.
 */
final readonly class ProductRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [new RecordSubject('product', ProductPermission::WRITE, ProductsModule::KEY, ['reference', 'name'])];
    }

    public function references(): array
    {
        return [
            RecordReference::uses('product_vendor', 'vendor_id', 'vendor', RecordMerge::MovesOrDrops, 'A product bought from the vendor names it; merged, the product is bought from the vendor kept, once.'),
            RecordReference::uses('product_barcode', 'supplier_id', 'vendor', RecordMerge::Moves, 'A vendor\'s own code for a product names it; merged, the code is the kept vendor\'s.'),
            RecordReference::ignored('product_cost_change', 'source_id', null, 'A cost changes from a receipt, a movement or a hand edit, never from a record a person deletes or merges.'),
            RecordReference::owned('product_barcode', 'product_id', 'product', RecordMerge::Moves, 'A product\'s codes are its own; merged, they read the product kept.'),
            RecordReference::owned('product_vendor', 'product_id', 'product', RecordMerge::MovesOrDrops, 'The vendors a product is bought from are its own; merged, the product kept is bought from each, once.'),
            RecordReference::owned('product_photo', 'product_id', 'product', RecordMerge::Moves, 'A product\'s photos are its own; merged, they join the gallery of the product kept.'),
            RecordReference::owned('product_cost_change', 'product_id', 'product', RecordMerge::Stays, 'A product\'s cost history is its own, and stays the history of the product merged away.'),
        ];
    }
}

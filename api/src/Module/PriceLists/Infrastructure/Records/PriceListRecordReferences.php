<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Infrastructure\Records;

use App\Module\PriceLists\Infrastructure\Module\PriceListsModule;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordSubject;

/**
 * A price list prices products, for everyone, a group or one customer: an item is the list's, not the product's, so a
 * product a list prices is used, and so is the customer a list is made for. A list is deleted as every record is, its
 * prices with it, and put back within the day; whoever writes products writes the lists.
 */
final readonly class PriceListRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [new RecordSubject('price_list', ProductPermission::WRITE, PriceListsModule::KEY, ['name'])];
    }

    public function references(): array
    {
        return [
            RecordReference::owned('price_list_item', 'price_list_id', 'price_list', RecordMerge::Ignored, 'A price is part of its list: it goes with it and comes back with it.'),
            RecordReference::uses('price_list_item', 'product_id', 'product', RecordMerge::MovesOrDrops, 'A price list item prices the product; merged, the list prices the product kept, once.')->droppingTwinsBy('price_list_id', 'min_quantity'),
            RecordReference::uses('price_list', 'customer_id', 'customer', RecordMerge::Moves, 'A price list made for a customer prices what it buys; merged, what the customer kept buys.'),
        ];
    }
}

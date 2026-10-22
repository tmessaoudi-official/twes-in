<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\Records;

use App\Records\Application\DeclaresRecordReferences;
use App\Records\Application\RecordLink;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Settings\Domain\ValueObject\SettingLevel;

/**
 * A setting is kept at a level, which `level_id` names as `<company id>/<record id>` (SettingAddress): a product's or a
 * product category's or a customer's settings go and come back with it.
 */
final readonly class SettingRecordReferences implements DeclaresRecordReferences
{
    public function subjects(): array
    {
        return [];
    }

    public function references(): array
    {
        return [
            RecordReference::ignored('setting', 'level_id', 'invoice', 'No setting is kept at an invoice\'s level.'),
            RecordReference::ignored('setting', 'level_id', 'quote', 'No setting is kept at a quote\'s level.'),
            RecordReference::ignored('setting', 'level_id', 'delivery_note', 'No setting is kept at a delivery note\'s level.'),
            RecordReference::ignored('setting', 'level_id', 'purchase_order', 'No setting is kept at an order\'s level.'),
            RecordReference::ignored('setting', 'level_id', 'payment_instrument', 'No setting is kept at a cheque\'s level.'),
            RecordReference::ignored('setting', 'level_id', 'recurring_invoice', 'No setting is kept at a recurring invoice\'s level.'),
            RecordReference::ignored('setting', 'level_id', 'recurring_expense', 'No setting is kept at a recurring expense\'s level.'),
            RecordReference::ignored('setting', 'level_id', 'vendor', 'No setting is kept at a vendor\'s level.'),
            RecordReference::owned('setting', 'level_id', 'product', RecordMerge::MovesOrDrops, 'A setting kept at a product\'s level is the product\'s own; merged, the product kept keeps its own where it has one.', RecordLink::CompanyPath)
                ->droppingTwinsBy('level', 'key')->where('level', SettingLevel::Product->value),
            RecordReference::owned('setting', 'level_id', 'product_category', RecordMerge::MovesOrDrops, 'A setting kept at a product category\'s level is the category\'s own; merged, the category kept keeps its own where it has one.', RecordLink::CompanyPath)
                ->droppingTwinsBy('level', 'key')->where('level', SettingLevel::ProductCategory->value),
            RecordReference::ignored('setting', 'level_id', 'expense_category', 'No setting is kept at an expense category\'s level.'),
            RecordReference::owned('setting', 'level_id', 'customer', RecordMerge::MovesOrDrops, 'A setting kept at a customer\'s level, its payment terms or credit limit, is the customer\'s own; merged, the customer kept keeps its own where it has one.', RecordLink::CompanyPath)
                ->droppingTwinsBy('level', 'key')->where('level', SettingLevel::Customer->value),
        ];
    }
}

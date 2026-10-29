<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Inbox;

use App\Inbox\Application\DeclaresNotificationKinds;
use App\Inbox\Application\NotificationAudience;
use App\Inbox\Application\NotificationKind;
use App\Module\Inventory\Application\TellStockKeepers;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;

/** What the stock tells whoever keeps it: a document whose goods did not all move, a count that differed, a low level. */
final readonly class StockNotificationKinds implements DeclaresNotificationKinds
{
    public function notificationKinds(): array
    {
        // The quantities each one writes, with the product's unit; the import's are counts of lines, written as they are.
        $quantities = [TellStockKeepers::COUNT_DIFFERENCE => ['difference'], TellStockKeepers::LOW => ['on_hand', 'point']];

        return array_map(static fn (string $type): NotificationKind => new NotificationKind($type, NotificationAudience::Company, InventoryModule::KEY, TellStockKeepers::PERMISSION, quantities: $quantities[$type] ?? [], unit: isset($quantities[$type]) ? 'unit' : null), [
            TellStockKeepers::LINES_LEFT_OUT,
            TellStockKeepers::MOVED_NO_STOCK,
            TellStockKeepers::INVOICE_LINES_LEFT_OUT,
            TellStockKeepers::INVOICE_MOVED_NO_STOCK,
            TellStockKeepers::CREDIT_LINES_NOT_RETURNED,
            TellStockKeepers::CREDIT_MOVED_NO_STOCK,
            TellStockKeepers::COUNT_DIFFERENCE,
            TellStockKeepers::IMPORTED,
            TellStockKeepers::LOW,
        ]);
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * Whether the customer screen says a product is in stock or out of it: a company setting each establishment may
 * override, off until the company turns it on, because a shop may not want a customer reading its shelves. The key is written out because the label gate
 * reads it from the source; a test keeps it equal to the constant.
 */
final readonly class CustomerScreenSettings implements DeclaresSettings
{
    public const string SHOW_STOCK = 'customer_screen.show_stock';

    public function settings(): iterable
    {
        yield new SettingDefinition('customer_screen.show_stock', SettingType::Bool, false, SettingChain::Articles, [SettingLevel::Company, SettingLevel::Establishment], 'settings.customer_screen.show_stock', MoveStockForDeliveryNotes::MODULE);
    }
}

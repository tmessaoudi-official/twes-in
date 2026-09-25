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
 * The thresholds of what the stock puts on « À surveiller » (docs/SPEC.md § 7, 2026-09-24 12:10), each a company
 * setting: a dated lot is watched this many days before it expires, and a product whose stock at the last 30 days'
 * pace lasts fewer days than it takes to restock.
 */
final readonly class StockWatchSettings implements DeclaresSettings
{
    public const string LOT_EXPIRY_DAYS = 'watch.lot_expiry_days';
    public const string LEAD_DAYS = 'watch.lead_days';

    public function settings(): iterable
    {
        $company = [SettingLevel::Company];

        yield new SettingDefinition(self::LOT_EXPIRY_DAYS, SettingType::Int, 30, SettingChain::Articles, $company, 'settings.watch.lot_expiry_days', MoveStockForDeliveryNotes::MODULE, min: 1, max: 365);
        yield new SettingDefinition(self::LEAD_DAYS, SettingType::Int, 7, SettingChain::Articles, $company, 'settings.watch.lead_days', MoveStockForDeliveryNotes::MODULE, min: 1, max: 90);
    }
}

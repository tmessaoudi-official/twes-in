<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\ShortSales;

use App\Module\Inventory\Application\MoveStockForDeliveryNotes;
use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use App\Shared\Application\ShortSales\ShortSaleRule;

/**
 * « Sortie sans stock enregistré »: what a sale taking more than the stock recorded does, set by the company, then each
 * establishment, then each role, the most specific winning. Allowed by default, as a shop starting with no stock entered
 * must sell from its first day. The key and the choices are written out because the label gate reads them from the
 * source; a test keeps them equal to the constant and to `ShortSaleRule`.
 */
final readonly class SaleWithoutStockSettings implements DeclaresSettings
{
    public const string KEY = 'stock.sale_without_stock';

    public function settings(): iterable
    {
        yield new SettingDefinition('stock.sale_without_stock', SettingType::Enum, ShortSaleRule::Allow->value, SettingChain::Articles, [SettingLevel::Company, SettingLevel::Establishment, SettingLevel::Role], 'settings.stock.sale_without_stock', MoveStockForDeliveryNotes::MODULE, choices: ['allow', 'confirm', 'refuse']);
    }

    /** The rule a value says; one it does not know allows, as the default does. */
    public static function ruleOf(mixed $chosen): ShortSaleRule
    {
        return (\is_string($chosen) ? ShortSaleRule::tryFrom($chosen) : null) ?? ShortSaleRule::Allow;
    }
}

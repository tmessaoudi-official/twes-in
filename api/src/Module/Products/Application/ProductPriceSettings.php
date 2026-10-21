<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;

/**
 * What a receipt that moves a product's cost does to its price: the company's choice, a category's when it prices its
 * own way. Offering by default, so a margin never moves without a word and no shelf price moves without someone
 * deciding. The key and the choices are written out because the label gate reads them from the source.
 */
final readonly class ProductPriceSettings implements DeclaresSettings
{
    public const string ON_COST_CHANGE = 'article.price_on_cost_change';
    private const string MODULE = 'products';

    public function settings(): iterable
    {
        yield new SettingDefinition('article.price_on_cost_change', SettingType::Enum, PriceOnCostChange::Offer->value, SettingChain::Articles, [SettingLevel::Company, SettingLevel::ProductCategory], 'settings.article.price_on_cost_change', self::MODULE, choices: ['offer', 'keep', 'follow']);
    }

    /** The mode a value of the setting says; one it does not know offers, as the default does. */
    public static function modeOf(mixed $chosen): PriceOnCostChange
    {
        return (\is_string($chosen) ? PriceOnCostChange::tryFrom($chosen) : null) ?? PriceOnCostChange::Offer;
    }
}

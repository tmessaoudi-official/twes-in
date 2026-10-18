<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\ReceiptCost;

use App\Module\Inventory\Application\MoveStockForDeliveryNotes;
use App\Module\Inventory\Domain\ValueObject\CostOnReceive;
use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;

/**
 * What a receipt does to a product's cost: a company setting, offering a choice by default. The key and the choices are
 * written out because the label gate reads them from the source; a test keeps them equal to the constant and to
 * `CostOnReceive`.
 */
final readonly class StockCostSettings implements DeclaresSettings
{
    public const string COST_ON_RECEIVE = 'stock.cost_on_receive';

    public function settings(): iterable
    {
        yield new SettingDefinition('stock.cost_on_receive', SettingType::Enum, CostOnReceive::Suggest->value, SettingChain::Articles, [SettingLevel::Company], 'settings.stock.cost_on_receive', MoveStockForDeliveryNotes::MODULE, choices: ['suggest', 'average', 'last', 'manual']);
    }

    /** The mode a value of the setting says; one it does not know leaves the choice to the person, as the default does. */
    public static function modeOf(mixed $chosen): CostOnReceive
    {
        return (\is_string($chosen) ? CostOnReceive::tryFrom($chosen) : null) ?? CostOnReceive::Suggest;
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\CostOnReceive;
use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

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
}

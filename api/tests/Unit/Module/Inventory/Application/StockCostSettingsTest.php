<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Application;

use App\Module\Inventory\Application\ReceiptCost\StockCostSettings;
use App\Module\Inventory\Domain\ValueObject\CostOnReceive;
use PHPUnit\Framework\TestCase;

final class StockCostSettingsTest extends TestCase
{
    public function testTheChoicesTheSettingOffersAreExactlyTheModesTheReceiptKnows(): void
    {
        $definitions = [];
        foreach (new StockCostSettings()->settings() as $definition) {
            $definitions[$definition->key] = $definition;
        }

        self::assertSame([StockCostSettings::COST_ON_RECEIVE, StockCostSettings::IMPORT_COST_FALLBACK], array_keys($definitions));
        $receive = $definitions[StockCostSettings::COST_ON_RECEIVE];
        self::assertSame(array_map(static fn (CostOnReceive $mode): string => $mode->value, CostOnReceive::cases()), $receive->choices, 'the label gate reads them from the source, so they are written out and this keeps them equal');
        self::assertSame(CostOnReceive::Suggest->value, $receive->default, 'a company chooses automatic costing, it is never done to it');
        // A stock addition naming no cost takes the product's, as before the setting, unless the company says otherwise.
        $fallback = $definitions[StockCostSettings::IMPORT_COST_FALLBACK];
        self::assertSame([[StockCostSettings::FALLBACK_PRODUCT, StockCostSettings::FALLBACK_AVERAGE], StockCostSettings::FALLBACK_PRODUCT], [$fallback->choices, $fallback->default]);
    }
}

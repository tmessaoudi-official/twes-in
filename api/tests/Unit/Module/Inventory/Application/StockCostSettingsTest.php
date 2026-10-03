<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Application;

use App\Module\Inventory\Application\StockCostSettings;
use App\Module\Inventory\Domain\CostOnReceive;
use PHPUnit\Framework\TestCase;

final class StockCostSettingsTest extends TestCase
{
    public function testTheChoicesTheSettingOffersAreExactlyTheModesTheReceiptKnows(): void
    {
        $definitions = [...new StockCostSettings()->settings()];

        self::assertCount(1, $definitions);
        self::assertSame(StockCostSettings::COST_ON_RECEIVE, $definitions[0]->key);
        self::assertSame(array_map(static fn (CostOnReceive $mode): string => $mode->value, CostOnReceive::cases()), $definitions[0]->choices, 'the label gate reads them from the source, so they are written out and this keeps them equal');
        self::assertSame(CostOnReceive::Suggest->value, $definitions[0]->default, 'a company chooses automatic costing, it is never done to it');
    }
}

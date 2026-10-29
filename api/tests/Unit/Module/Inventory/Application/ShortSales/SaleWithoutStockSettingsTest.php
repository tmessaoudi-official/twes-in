<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Application\ShortSales;

use App\Module\Inventory\Application\ShortSales\SaleWithoutStockSettings;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Application\ShortSales\ShortSaleConsent;
use App\Shared\Application\ShortSales\ShortSaleRule;
use PHPUnit\Framework\TestCase;

final class SaleWithoutStockSettingsTest extends TestCase
{
    public function testTheChoicesAreExactlyTheRulesAndASaleIsAllowedUnlessSaidOtherwise(): void
    {
        $definitions = [...new SaleWithoutStockSettings()->settings()];

        self::assertCount(1, $definitions);
        self::assertSame(SaleWithoutStockSettings::KEY, $definitions[0]->key);
        self::assertSame(array_map(static fn (ShortSaleRule $rule): string => $rule->value, ShortSaleRule::cases()), $definitions[0]->choices, 'the label gate reads them from the source, so they are written out and this keeps them equal');
        self::assertSame(ShortSaleRule::Allow->value, $definitions[0]->default, 'a shop starting with no stock entered sells from its first day');
        self::assertSame([SettingLevel::Company, SettingLevel::Establishment, SettingLevel::Role], $definitions[0]->overridableAt);
        self::assertSame(ShortSaleRule::Allow, SaleWithoutStockSettings::ruleOf('unknown'));
    }

    public function testTheRightToConfirmIsTheStockModulesOwnPermission(): void
    {
        self::assertSame(StockPermission::SELL_SHORT, ShortSaleConsent::PERMISSION, 'the gates read the stock module\'s, the documents that sell ask the shared one');
    }
}

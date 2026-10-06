<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Application;

use App\Module\Inventory\Application\CustomerScreenSettings;
use App\Settings\Domain\SettingLevel;
use PHPUnit\Framework\TestCase;

final class CustomerScreenSettingsTest extends TestCase
{
    public function testTheCustomerScreenKeepsStockToItselfUntilTheCompanyTurnsItOnAndEachEstablishmentMayDecideForItself(): void
    {
        $definitions = [...new CustomerScreenSettings()->settings()];

        self::assertCount(1, $definitions);
        self::assertSame(CustomerScreenSettings::SHOW_STOCK, $definitions[0]->key, 'the label gate reads the key from the source, so it is written out and this keeps it equal');
        self::assertFalse($definitions[0]->default, 'a shop does not show its shelves to a customer unless it chose to');
        self::assertSame([SettingLevel::Company, SettingLevel::Establishment], $definitions[0]->overridableAt);
    }
}

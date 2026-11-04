<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Demo;

use App\Demo\Application\DemoSettings;
use App\Settings\Domain\ValueObject\SettingDefinition;
use PHPUnit\Framework\TestCase;

/**
 * The demo is on by default on a server and off on a shop's own computer, where a visitor would own a sandbox on the
 * shop's network and pass every identity check: the default follows the installation itself, so a shop whose install
 * step never switched it off is closed all the same. An operator's choice still decides over either default.
 */
final class DemoSettingsTest extends TestCase
{
    public function testOnAServerTheDemoIsOnByDefault(): void
    {
        self::assertTrue(self::enabled(new DemoSettings('server'))->default);
    }

    public function testOnAShopsOwnComputerTheDemoIsOffByDefault(): void
    {
        self::assertFalse(self::enabled(new DemoSettings('onprem'))->default);
    }

    public function testAnInstallationOfNoKnownKindIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_INSTALLATION is "shop"; it must be "server" or "onprem".');

        new DemoSettings('shop');
    }

    private static function enabled(DemoSettings $settings): SettingDefinition
    {
        foreach ($settings->settings() as $definition) {
            if (DemoSettings::ENABLED === $definition->key) {
                return $definition;
            }
        }
        self::fail('the demo declares no demo.enabled');
    }
}

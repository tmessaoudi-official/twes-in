<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Application;

use App\Fiscal\Application\Preset\FiscalPreset;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\PlatformCountrySettings;
use App\Fiscal\Application\Preset\UnknownFiscalPreset;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use PHPUnit\Framework\TestCase;

/** The platform's country is one a fiscal preset is written for, the installation's own until an operator picks another. */
final class PlatformCountrySettingsTest extends TestCase
{
    public function testItOffersThePresetsCountriesAndStartsOnTheInstallationsOwn(): void
    {
        $settings = [...(new PlatformCountrySettings(self::presets(['FR', 'TN']), 'FR'))->settings()];

        self::assertCount(1, $settings);
        $country = $settings[0];
        self::assertSame(
            [PlatformCountrySettings::COUNTRY, SettingType::Enum, 'FR', ['FR', 'TN'], SettingChain::Platform, [SettingLevel::Platform]],
            [$country->key, $country->type, $country->default, $country->choices, $country->chain, $country->overridableAt],
        );
    }

    /** An installation naming a country no preset is written for is refused when the settings are read, never stored. */
    public function testAnInstallationsCountryWithNoPresetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        [...(new PlatformCountrySettings(self::presets(['FR', 'TN']), 'DZ'))->settings()];
    }

    /** @param list<string> $keys */
    private static function presets(array $keys): FiscalPresets
    {
        return new class($keys) implements FiscalPresets {
            /** @param list<string> $keys */
            public function __construct(private readonly array $keys)
            {
            }

            public function keys(): array
            {
                return $this->keys;
            }

            public function has(string $key): bool
            {
                return \in_array($key, $this->keys, true);
            }

            public function get(string $key): FiscalPreset
            {
                throw new UnknownFiscalPreset($key);
            }
        };
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;

/**
 * The demo, on by default; a shop's own installation switches it off as it installs (SwitchDemoOffAtInstall), and the
 * platform's operators switch it on or off. How many
 * sandboxes may exist at once bounds what visitors cost the machine: every one is a whole company with its history.
 */
final readonly class DemoSettings implements DeclaresSettings
{
    public const string MODULE = 'core';
    public const string ENABLED = 'demo.enabled';
    public const string MOST = 'demo.sandboxes_max';
    public const int DEFAULT_MOST = 10;

    public function settings(): iterable
    {
        yield new SettingDefinition(self::ENABLED, SettingType::Bool, true, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.demo_enabled', self::MODULE);
        // At least one place per country, so a claimed sandbox can always be replaced while the other waits.
        yield new SettingDefinition(self::MOST, SettingType::Int, self::DEFAULT_MOST, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.demo_sandboxes_max', self::MODULE, min: 3, max: 100);
    }
}

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
 * The demo, on by default on a server and off on a shop's own computer (APP_INSTALLATION=onprem), where a visitor would
 * own a sandbox on the shop's network; the platform's operators switch it on or off either way, and a shop's install
 * still writes its choice down (SwitchDemoOffAtInstall), so an older release run on its database keeps it closed. How many
 * sandboxes may exist at once bounds what visitors cost the machine: every one is a whole company with its history.
 */
final readonly class DemoSettings implements DeclaresSettings
{
    public const string MODULE = 'core';
    public const string ENABLED = 'demo.enabled';
    public const string MOST = 'demo.sandboxes_max';
    public const int DEFAULT_MOST = 10;
    public const string SERVER = 'server';
    public const string SHOP = 'onprem';

    /** @param string $installation APP_INSTALLATION: « server », or « onprem » on a shop's own computer */
    public function __construct(private string $installation)
    {
        if (!\in_array($installation, [self::SERVER, self::SHOP], true)) {
            throw new \InvalidArgumentException(\sprintf('APP_INSTALLATION is "%s"; it must be "server" or "onprem".', $installation));
        }
    }

    public function settings(): iterable
    {
        yield new SettingDefinition(self::ENABLED, SettingType::Bool, self::SHOP !== $this->installation, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.demo_enabled', self::MODULE);
        // At least one place per country, so a claimed sandbox can always be replaced while the other waits.
        yield new SettingDefinition(self::MOST, SettingType::Int, self::DEFAULT_MOST, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.demo_sandboxes_max', self::MODULE, min: 3, max: 100);
    }
}

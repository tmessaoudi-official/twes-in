<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Application;

use App\Settings\Application\ChangeSettings;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;

/**
 * A shop's own installation has no demo: a visitor would own a sandbox there and pass every identity check its
 * installation's acts ask for. The installation script switches it off as it installs, and only where nobody has
 * chosen yet, so an operator who switched it on again keeps it on.
 */
final readonly class SwitchDemoOffAtInstall
{
    public function __construct(private ResolveSettings $resolve, private ChangeSettings $change)
    {
    }

    /** @return bool whether the demo was switched off now */
    public function handle(): bool
    {
        $platform = new SettingContext();
        if (null !== $this->resolve->one($platform, DemoSettings::ENABLED)->source) {
            return false;
        }
        $this->change->change($platform, DemoSettings::ENABLED, SettingLevel::Platform, false, null);

        return true;
    }
}

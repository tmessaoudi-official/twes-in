<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Application\PlatformSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;

/**
 * Whether anyone at this installation may sign in with a passkey alone. On until its operators turn it off: each person
 * still switches it on for their own account, which is where it is off by default, and the account's own switch is not
 * a setting, so that no level above can turn it on for someone.
 */
final readonly class PasskeySignInSettings implements DeclaresSettings
{
    public const string ALLOWED = 'identity.passkey_sign_in';

    public function settings(): iterable
    {
        yield new SettingDefinition(self::ALLOWED, SettingType::Bool, true, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.passkey_sign_in', PlatformSettings::MODULE);
    }
}

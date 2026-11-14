<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa\Passkey;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Application\PlatformSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;

/**
 * Whether passkeys are bound to the shop's Tailscale name rather than the installation's own. Off as shipped: a shop's
 * passkeys are made on its own name, and every one made before a switch stops working after it, either way;
 * `PasskeysFollowTheirName` says when the switch may move. Declared apart from that rule, which reads settings itself.
 */
final readonly class PasskeyHostSettings implements DeclaresSettings
{
    public const string ON_TAILNET_NAME = 'identity.passkeys_on_tailnet_name';

    public function settings(): iterable
    {
        yield new SettingDefinition(self::ON_TAILNET_NAME, SettingType::Bool, false, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.passkeys_on_tailnet_name', PlatformSettings::MODULE);
    }
}

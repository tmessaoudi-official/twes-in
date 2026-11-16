<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;

/**
 * The platform chain (docs/SPEC.md § 3 Settings): what the platform's operators decide for everyone. Signup is off
 * until an operator opens it, and a company that signs up waits for an operator's approval unless that is turned off.
 */
final readonly class PlatformSettings implements DeclaresSettings
{
    public const string MODULE = 'core';
    public const string SIGNUP_ENABLED = 'signup.enabled';
    public const string SIGNUP_APPROVAL_REQUIRED = 'signup.approval_required';
    /** Whether a second « Suspendre » with another reason says it anew to the owners; off, the first reason stays. */
    public const string RESUSPEND_UPDATES_REASON = 'approval.resuspend_updates_reason';

    public function settings(): iterable
    {
        $chain = SettingChain::Platform;
        $platform = [SettingLevel::Platform];

        yield new SettingDefinition(self::SIGNUP_ENABLED, SettingType::Bool, false, $chain, $platform, 'settings.platform.signup_enabled', self::MODULE);
        yield new SettingDefinition(self::SIGNUP_APPROVAL_REQUIRED, SettingType::Bool, true, $chain, $platform, 'settings.platform.signup_approval_required', self::MODULE);
        yield new SettingDefinition(self::RESUSPEND_UPDATES_REASON, SettingType::Bool, false, $chain, $platform, 'settings.platform.resuspend_updates_reason', self::MODULE);
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Signup;

use App\Fiscal\Application\Preset\FiscalPresets;
use App\Settings\Application\PlatformSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;

/** What the platform's operators decided about signup (docs/SPEC.md § 3 Settings), read fresh on every request. */
final readonly class SignupPolicy
{
    /** The languages the application speaks; anything else asked for is answered in French. */
    public const array LOCALES = ['fr', 'en'];

    public function __construct(private ReadSetting $settings, private FiscalPresets $presets)
    {
    }

    public function isOpen(): bool
    {
        return true === $this->settings->value(new SettingContext(), PlatformSettings::SIGNUP_ENABLED);
    }

    public function approvalRequired(): bool
    {
        return false !== $this->settings->value(new SettingContext(), PlatformSettings::SIGNUP_APPROVAL_REQUIRED);
    }

    /** @return list<string> the countries a company may be opened in: those with a fiscal preset */
    public function countries(): array
    {
        return $this->presets->keys();
    }

    public static function locale(?string $asked): string
    {
        return \in_array($asked, self::LOCALES, true) ? $asked : self::LOCALES[0];
    }
}

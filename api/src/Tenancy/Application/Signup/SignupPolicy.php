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
use App\Shared\Application\Languages;

/** What the platform's operators decided about signup (docs/SPEC.md § 3 Settings), read fresh on every request. */
final readonly class SignupPolicy
{
    public function __construct(private ReadSetting $settings, private FiscalPresets $presets, private Languages $languages)
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

    /** A language the interface speaks; anything else asked for is answered in the default one. */
    public function locale(?string $asked): string
    {
        return $this->languages->interfaceOr($asked);
    }
}

<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\Phones;

use App\Settings\Application\PhoneSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\Phones\PhonePolicy;

/** The platform's policy for a phone outside the ranges handed out, as its operators set it. */
final readonly class SettingPhonePolicy implements PhonePolicy
{
    public function __construct(private ReadSetting $settings)
    {
    }

    public function keepsOutsideRanges(): bool
    {
        return PhoneSettings::KEEP === $this->settings->value(new SettingContext(), PhoneSettings::OUTSIDE_RANGES);
    }
}

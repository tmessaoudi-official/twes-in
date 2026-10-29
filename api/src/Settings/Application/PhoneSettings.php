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
 * The platform's policy for a phone of the right length outside the ranges its country hands out (ruled 2026-10-29):
 * « Refuser », the default, refuses it as typed with its country's example; « Garder et marquer À vérifier » keeps it
 * and marks the record. The API's `TypedPhones` and the screens' phone field read the same value, so they never differ.
 */
final readonly class PhoneSettings implements DeclaresSettings
{
    public const string MODULE = 'core';
    public const string OUTSIDE_RANGES = 'platform.phones.outside_ranges';
    public const string REFUSE = 'refuse';
    public const string KEEP = 'keep';

    public function settings(): iterable
    {
        yield new SettingDefinition(self::OUTSIDE_RANGES, SettingType::Enum, self::REFUSE, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.phones.outside_ranges', self::MODULE, choices: [self::REFUSE, self::KEEP]);
    }
}

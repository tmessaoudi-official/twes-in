<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * The reminder calendar, kept as data (MON-16): the days late at which each stage is reached, the company's hour from
 * which a day's stages are recorded, and whether a customer is reminded at all, which a group or a customer may turn
 * off for themselves. On by default, because reminding is what the ruling adopted; only the late fee is off until
 * asked for.
 */
final readonly class ReminderSettings implements DeclaresSettings
{
    public const string ENABLED = 'reminders.enabled';
    public const string STAGES = 'reminders.stages';
    public const string HOUR = 'reminders.hour';
    /** Up to five stages, each a number of days late, written « 7, 15, 30 ». */
    public const string STAGES_PATTERN = '/^\s*\d{1,3}(\s*,\s*\d{1,3}){0,4}\s*$/';
    /** The module's key, as InvoicesModule declares it. */
    private const string MODULE = 'invoices';

    public function settings(): iterable
    {
        yield new SettingDefinition(self::ENABLED, SettingType::Bool, true, SettingChain::Parties, [SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer], 'settings.reminders.enabled', self::MODULE);
        yield new SettingDefinition(self::STAGES, SettingType::Text, '7, 15, 30', SettingChain::Parties, [SettingLevel::Company], 'settings.reminders.stages', self::MODULE, pattern: self::STAGES_PATTERN);
        yield new SettingDefinition(self::HOUR, SettingType::Int, 9, SettingChain::Parties, [SettingLevel::Company], 'settings.reminders.hour', self::MODULE, min: 0, max: 23);
    }

    /**
     * The stages as days late, smallest first, each once and none at zero: « 30, 7, 7 » is the two stages 7 and 30.
     *
     * @return list<int>
     */
    public static function stages(string $written): array
    {
        if (1 !== preg_match(self::STAGES_PATTERN, $written)) {
            return [];
        }
        $days = array_values(array_unique(array_filter(array_map(intval(...), explode(',', $written)), static fn (int $day): bool => $day > 0)));
        sort($days);

        return $days;
    }
}

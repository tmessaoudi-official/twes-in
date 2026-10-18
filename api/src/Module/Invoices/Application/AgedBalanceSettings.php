<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;

/**
 * Where the aged balance cuts lateness, each a company setting: the last day of each of its three brackets of days late,
 * past the last of which an invoice is in the open bracket. Read in order whatever order they were set in, so two equal
 * bounds make one bracket fewer rather than an empty one.
 */
final readonly class AgedBalanceSettings implements DeclaresSettings
{
    public const string FIRST_DAYS = 'aged_balance.first_days';
    public const string SECOND_DAYS = 'aged_balance.second_days';
    public const string THIRD_DAYS = 'aged_balance.third_days';

    /** The module's key, as InvoicesModule declares it. */
    private const string MODULE = 'invoices';

    public function settings(): iterable
    {
        $company = [SettingLevel::Company];
        yield new SettingDefinition(self::FIRST_DAYS, SettingType::Int, 30, SettingChain::Parties, $company, 'settings.aged_balance.first_days', self::MODULE, min: 1, max: 730);
        yield new SettingDefinition(self::SECOND_DAYS, SettingType::Int, 60, SettingChain::Parties, $company, 'settings.aged_balance.second_days', self::MODULE, min: 1, max: 730);
        yield new SettingDefinition(self::THIRD_DAYS, SettingType::Int, 90, SettingChain::Parties, $company, 'settings.aged_balance.third_days', self::MODULE, min: 1, max: 730);
    }
}

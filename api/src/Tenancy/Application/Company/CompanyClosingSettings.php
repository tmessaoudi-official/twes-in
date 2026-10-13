<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * How long the owner of a company closed down may take it back; after that the closing is final and the company waits,
 * read-only, for its retention to end. The platform's operators set it; at least a day, so a mistaken click can be undone.
 */
final readonly class CompanyClosingSettings implements DeclaresSettings
{
    public const string MODULE = 'core';
    public const string REOPEN_DAYS = 'company.reopen_days';
    public const int DEFAULT_DAYS = 7;
    public const int MAX_DAYS = 90;

    public function settings(): iterable
    {
        yield new SettingDefinition(self::REOPEN_DAYS, SettingType::Int, self::DEFAULT_DAYS, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.company_reopen_days', self::MODULE, min: 1, max: self::MAX_DAYS);
    }
}

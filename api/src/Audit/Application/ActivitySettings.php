<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * How far back the company's activity journal reaches: twelve months unless the company keeps more, up to three years
 * (docs/SPEC.md § 7, 2026-09-26 23:04). Never less than twelve: a shorter journal would let a company forget what was
 * done in it within the year it is accountable for.
 */
final readonly class ActivitySettings implements DeclaresSettings
{
    public const string RETENTION_MONTHS = 'activity.retention_months';
    public const int DEFAULT_RETENTION_MONTHS = 12;
    public const int MAX_RETENTION_MONTHS = 36;
    /** Not a module: the journal cannot be switched off. */
    private const string MODULE = 'core';

    public function settings(): iterable
    {
        yield new SettingDefinition(self::RETENTION_MONTHS, SettingType::Int, self::DEFAULT_RETENTION_MONTHS, SettingChain::Parties, [SettingLevel::Company], 'settings.activity.retention_months', self::MODULE, min: self::DEFAULT_RETENTION_MONTHS, max: self::MAX_RETENTION_MONTHS);
    }
}

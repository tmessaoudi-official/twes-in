<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Application;

use App\Licensing\Domain\SubscriptionTerms;
use App\Licensing\Domain\UnpaidMode;
use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * The platform's licensing defaults (docs/SPEC.md § 7, 2026-09-17). They live in the platform chain only, which operators
 * alone change: declared in a business chain, a company's admin could edit the company level and set their own lock.
 * A subscription's own terms override them per company.
 */
final readonly class LicensingSettings implements DeclaresSettings
{
    public const string MODULE = 'core';
    public const string GRACE_DAYS = 'licensing.grace_days';
    public const string UNPAID_MODE = 'licensing.unpaid_mode';

    public function settings(): iterable
    {
        $chain = SettingChain::Platform;
        $platform = [SettingLevel::Platform];

        yield new SettingDefinition(self::GRACE_DAYS, SettingType::Int, 7, $chain, $platform, 'settings.platform.licensing_grace_days', self::MODULE, min: 0, max: SubscriptionTerms::MAX_GRACE_DAYS);
        yield new SettingDefinition(self::UNPAID_MODE, SettingType::Enum, UnpaidMode::ReadOnly->value, $chain, $platform, 'settings.platform.licensing_unpaid_mode', self::MODULE, choices: array_map(static fn (UnpaidMode $mode): string => $mode->value, UnpaidMode::cases()));
    }
}

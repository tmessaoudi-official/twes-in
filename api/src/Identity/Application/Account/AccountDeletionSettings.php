<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * How long an account asked to be deleted waits before the nightly run deletes it, during which signing in again takes
 * it back. The platform's operators set it; at least a day, so a mistaken click is never final before the mail arrives.
 */
final readonly class AccountDeletionSettings implements DeclaresSettings
{
    public const string MODULE = 'core';
    public const string DAYS = 'account.deletion_days';
    public const int DEFAULT_DAYS = 7;
    public const int MAX_DAYS = 90;

    public function settings(): iterable
    {
        yield new SettingDefinition(self::DAYS, SettingType::Int, self::DEFAULT_DAYS, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.account_deletion_days', self::MODULE, min: 1, max: self::MAX_DAYS);
    }
}

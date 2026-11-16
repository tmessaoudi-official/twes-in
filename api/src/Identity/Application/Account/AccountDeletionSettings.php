<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;

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
    /**
     * What the nightly run does with a deletion asked for by someone who has since become a company's only owner: off,
     * it waits and tries again the next night; on, it withdraws the request and tells them. The last owner stays either way.
     */
    public const string WITHDRAWN_WHEN_SOLE_OWNER = 'account.deletion_withdrawn_when_sole_owner';

    public function settings(): iterable
    {
        yield new SettingDefinition(self::DAYS, SettingType::Int, self::DEFAULT_DAYS, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.account_deletion_days', self::MODULE, min: 1, max: self::MAX_DAYS);
        yield new SettingDefinition(self::WITHDRAWN_WHEN_SOLE_OWNER, SettingType::Bool, false, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.account_deletion_withdrawn_when_sole_owner', self::MODULE);
    }
}

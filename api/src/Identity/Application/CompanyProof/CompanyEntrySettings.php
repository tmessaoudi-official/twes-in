<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\CompanyProof;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;

/**
 * Whether a company, or one of its roles, takes a passkey alone as the proof of whoever enters it. On unless the
 * company or the role says otherwise; the installation's own switch (PasskeySignInSettings) comes first, and the
 * password, with the authenticator code where the company requires a second factor, is taken everywhere.
 */
final readonly class CompanyEntrySettings implements DeclaresSettings
{
    public const string PASSKEY = 'identity.passkey_entry';
    private const string MODULE = 'core';

    public function settings(): iterable
    {
        yield new SettingDefinition(self::PASSKEY, SettingType::Bool, true, SettingChain::Presentation, [SettingLevel::Company, SettingLevel::Role], 'settings.presentation.passkey_entry', self::MODULE);
    }
}

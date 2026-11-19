<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Whether an admin gives the admin role, by invitation or by a change of role, as by default, or the company keeps it to
 * its owners. Only tightens: an admin never gives the owner's role, never lowers another admin, and an owner gives every
 * role whatever is chosen. Declared after the merged vendor's row, so the settings page draws it on a row of its own.
 */
#[AsTaggedItem(priority: -205)]
final readonly class AdminGrantsSettings implements DeclaresSettings
{
    public const string ADMIN_GRANTS_ADMIN = 'members.admin_grants_admin';
    /** The core's settings belong to no module. */
    private const string MODULE = 'core';

    public function settings(): iterable
    {
        // The key written out, as the setting-labels gate reads it from the source.
        yield new SettingDefinition('members.admin_grants_admin', SettingType::Bool, true, SettingChain::Parties, [SettingLevel::Company], 'settings.members.admin_grants_admin', self::MODULE);
    }

    /** Whether the company lets its admins give the admin role. */
    public static function adminGrantsAdmin(ReadSetting $settings, Company $company): bool
    {
        return true === $settings->value(new SettingContext($company), self::ADMIN_GRANTS_ADMIN);
    }
}

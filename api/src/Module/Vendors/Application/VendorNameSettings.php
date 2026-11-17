<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Which vendor a screen names for a record made with a vendor merged away since: the one kept, as it does by default, or
 * the one recorded, by its own name. The books keep the vendor recorded whatever is chosen, and a picker offers the
 * vendor kept alone. The modules showing a vendor read the key and its choices as constants. Declared after every
 * other, so the settings page draws it on the last row of its section, a whole one, and moves no pair before it.
 */
#[AsTaggedItem(priority: -200)]
final readonly class VendorNameSettings implements DeclaresSettings
{
    public const string MERGED_NAMES = 'vendors.merged_names_on_screens';
    public const string KEPT = 'kept';
    public const string RECORDED = 'recorded';
    private const string MODULE = 'vendors';

    public function settings(): iterable
    {
        // The key and its choices written out, as the setting-labels gate reads them.
        yield new SettingDefinition('vendors.merged_names_on_screens', SettingType::Enum, 'kept', SettingChain::Parties, [SettingLevel::Company], 'settings.vendors.merged_names_on_screens', self::MODULE, choices: ['kept', 'recorded']);
    }
}
